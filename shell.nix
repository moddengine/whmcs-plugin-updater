{ pkgs ? import <nixpkgs> { } }:

let
  php = pkgs.php83.buildEnv {
    extensions = { enabled, all }: enabled ++ (with all; [ curl posix zip ]);
  };
in
pkgs.mkShell {
  packages = [ php pkgs.php83Packages.composer ];
}
