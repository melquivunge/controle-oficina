#!/bin/sh
# Preparação única da VPS Ubuntu, como root. Pode ser repetida. Uso: preparar-servidor.sh "<chave pública SSH do deploy>"
# Não desliga o login por senha: isso só depois de confirmar o acesso por chave.
set -eu
CHAVE="$1"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -yq ca-certificates curl openssl ufw fail2ban unattended-upgrades
command -v docker >/dev/null || curl -fsSL https://get.docker.com | sh
systemctl enable --now docker fail2ban

PORTA_SSH="$(sshd -T 2>/dev/null | awk '$1=="port"{print $2; exit}')"
ufw allow "${PORTA_SSH:-22}/tcp"
ufw allow 80/tcp
ufw allow 443
ufw --force enable

id deploy >/dev/null 2>&1 || useradd --create-home --shell /bin/bash deploy
usermod -aG docker deploy
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
touch /home/deploy/.ssh/authorized_keys
grep -qxF "$CHAVE" /home/deploy/.ssh/authorized_keys || echo "$CHAVE" >> /home/deploy/.ssh/authorized_keys
chown deploy:deploy /home/deploy/.ssh/authorized_keys; chmod 600 /home/deploy/.ssh/authorized_keys
install -d -o deploy -g deploy /opt/oficina /opt/oficina/edge /opt/oficina/production /opt/oficina/staging

# Backup diário da produção, guardado por 14 dias. Não substitui a cópia fora do servidor.
cat > /etc/cron.d/oficina-backup <<'CRON'
0 6 * * * deploy cd /opt/oficina/production && docker/scripts/backup.sh >/dev/null && find backups -mindepth 1 -maxdepth 1 -type d -mtime +14 -exec rm -rf {} +
CRON
chmod 644 /etc/cron.d/oficina-backup
echo "Servidor preparado. Porta SSH: ${PORTA_SSH:-22}"
