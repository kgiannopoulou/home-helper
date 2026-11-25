#!/usr/bin/env bash
# One-time setup of a fresh Oracle Cloud Always Free VM (Ubuntu 24.04, Ampere A1 / ARM64).
# Run it on the VM as the default `ubuntu` user:
#
#   curl -fsSL https://raw.githubusercontent.com/<you>/home-helper/main/deploy/oracle-bootstrap.sh | bash
#   (or copy it over with scp and run: bash oracle-bootstrap.sh)
#
# It installs Docker, opens ports 80/443 in the VM's own firewall (Oracle's Ubuntu images
# block everything but SSH with iptables, on top of the cloud Security List), adds swap,
# and turns on automatic security updates. It creates nothing in your Oracle account,
# so it can't create a paid resource.
set -euo pipefail

echo "== Docker"
if ! command -v docker >/dev/null; then
  curl -fsSL https://get.docker.com | sudo sh
  sudo usermod -aG docker "$USER"
fi

echo "== Firewall: allow HTTP and HTTPS (TCP 80/443, UDP 443 for HTTP/3)"
for rule in "-p tcp --dport 80" "-p tcp --dport 443" "-p udp --dport 443"; do
  # shellcheck disable=SC2086
  sudo iptables -C INPUT -m state --state NEW $rule -j ACCEPT 2>/dev/null \
    || sudo iptables -I INPUT 6 -m state --state NEW $rule -j ACCEPT
done
sudo apt-get update -y
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y iptables-persistent unattended-upgrades git
sudo netfilter-persistent save

echo "== Swap (2 GB), so a build never runs out of memory"
if ! swapon --show | grep -q /swapfile; then
  sudo fallocate -l 2G /swapfile
  sudo chmod 600 /swapfile
  sudo mkswap /swapfile
  sudo swapon /swapfile
  echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
fi

echo "== Automatic security updates"
sudo dpkg-reconfigure -f noninteractive unattended-upgrades

echo
echo "Done. Log out and back in (for the docker group), then:"
echo "  git clone https://github.com/<you>/home-helper.git && cd home-helper"
echo "  cp deploy/.env.example deploy/.env && nano deploy/.env"
echo "  docker compose -f deploy/compose.yaml --env-file deploy/.env up -d --build"
