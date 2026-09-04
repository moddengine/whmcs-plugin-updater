{ pkgs ? import <nixpkgs> { } }:

let
  php = pkgs.php83.buildEnv {
    extensions = { enabled, all }: enabled ++ (with all; [ curl posix zip ]);
  };
in
pkgs.mkShell {
  packages = with pkgs; [
    actionlint
    gh
    jq
    php
    php83Packages.composer
    unzip
    zip
  ];
}
