#!/usr/bin/env perl
# Fails unless the generated stubs serve EXACTLY the RPCs the .proto files declare.
#
#   perl tools/check-generated-rpcs.pl <proto dir> <generated src dir>
#
# `make check_build` runs it against the ondewo-sip-api submodule. The file-level half of
# check_build only proves that every .proto produced *a* PHP file; this compares the method paths
# (/<package>.<Service>/<Rpc>) the .proto files declare with the ones the generated *Client.php
# stubs call, in both directions, so stubs that are stale against the pinned api - an RPC added,
# renamed or removed without regenerating - fail the release before anything is committed.
# Plain perl with no modules: the release needs nothing else on the host.
use strict;
use warnings;
use File::Find;

my ($proto_dir, $src_dir) = @ARGV;
die "usage: $0 <proto dir> <generated src dir>\n" unless defined $src_dir && -d $proto_dir && -d $src_dir;

my (%declared, %generated);

find({ no_chdir => 1, wanted => sub {
    return unless /\.proto$/;
    open my $fh, '<', $_ or die "cannot read $_: $!\n";
    local $/;
    my $text = <$fh>;
    close $fh;
    # Comments, but not comment-like text inside strings: the HTTP annotations are full of
    # "/v2/{parent=projects/*}/..." paths, which a naive /* ... */ strip eats across services.
    $text =~ s{^\s*/\*.*?\*/}{}gms;                   # block comments opening a line
    $text =~ s{^((?:[^"\n]|"[^"\n]*")*?)//[^\n]*}{$1}gm; # line comments outside "strings"
    my ($package) = $text =~ /^\s*package\s+([\w.]+)\s*;/m;
    while ($text =~ /\bservice\s+(\w+)\s*\{(.*?)\n\}/gs) {
        my ($service, $body) = ($1, $2);
        $declared{"/" . ($package ? "$package." : "") . "$service/$1"} = $File::Find::name
            while $body =~ /\brpc\s+(\w+)\s*\(/g;
    }
} }, $proto_dir);

find({ no_chdir => 1, wanted => sub {
    return unless /Client\.php$/;
    open my $fh, '<', $_ or die "cannot read $_: $!\n";
    local $/;
    my $text = <$fh>;
    close $fh;
    $generated{$1} = $File::Find::name while $text =~ /->_\w+Request\(\s*'(\/[\w.]+\/\w+)'/g;
} }, $src_dir);

my @missing = sort grep { !exists $generated{$_} } keys %declared;
my @stale   = sort grep { !exists $declared{$_} } keys %generated;
print "declared in the .proto files but not generated: $_ ($declared{$_})\n" for @missing;
print "generated but declared in no .proto file: $_ ($generated{$_})\n" for @stale;
die "no rpc found under $proto_dir\n" unless %declared;
exit(@missing || @stale ? 1 : 0);
