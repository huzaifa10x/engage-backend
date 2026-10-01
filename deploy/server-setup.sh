#!/usr/bin/env bash
# One-time preparation of a fresh Oracle Cloud Ubuntu 24.04 VM. Run as the `ubuntu` user:
#   bash /opt/engage/backend/deploy/server-setup.sh
set -euo pipefail

echo "▸ System updates"
sudo apt-get update -y
sudo DEBIAN_FRONTEND=noninteractive apt-get upgrade -y
sudo apt-get install -y ca-certificates curl git netfilter-persistent iptables-persistent

echo "▸ Docker"
if ! command -v docker >/dev/null; then
    curl -fsSL https://get.docker.com | sudo sh
fi
sudo usermod -aG docker "$USER"
sudo systemctl enable --now docker

echo "▸ Firewall (Oracle Ubuntu images block everything except SSH by default)"
for rule in "tcp 80" "tcp 443" "udp 443"; do
    set -- $rule
    if ! sudo iptables -C INPUT -p "$1" --dport "$2" -m state --state NEW -j ACCEPT 2>/dev/null; then
        sudo iptables -I INPUT 5 -p "$1" --dport "$2" -m state --state NEW -j ACCEPT
    fi
done
sudo netfilter-persistent save

echo "▸ Nightly backups at 03:15"
mkdir -p /opt/engage/backups
( crontab -l 2>/dev/null | grep -v engage/backend/deploy/backup.sh; echo "15 3 * * * bash /opt/engage/backend/deploy/backup.sh >> /opt/engage/backups/backup.log 2>&1" ) | crontab -

echo "▸ 'dc' shortcut for the production stack (e.g. dc ps, dc logs -f app)"
grep -q "alias dc=" ~/.bashrc || echo "alias dc='docker compose --project-directory /opt/engage/backend/deploy -f /opt/engage/backend/deploy/docker-compose.prod.yml'" >> ~/.bashrc

echo
echo "✅ Server ready. Log out and back in (so the docker group applies), then continue with the guide."
