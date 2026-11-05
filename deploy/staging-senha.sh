#!/bin/bash
# Define (ou troca) o usuário e a senha que protegem staging.controleoficina.com.br. Rodar no servidor:
#   ssh -t deploy@<IP> /opt/oficina/staging/deploy/staging-senha.sh
# A senha é digitada aqui, vira hash no Caddy e nunca é gravada em texto.
set -euo pipefail
cd /opt/oficina/edge
read -rp "Usuário de acesso ao staging [equipe]: " U; U=${U:-equipe}
[[ "$U" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "Use só letras, números, ponto, hífen ou sublinhado." >&2; exit 1; }
read -rsp "Senha (mínimo 12 caracteres): " P; echo
read -rsp "Repita a senha: " P2; echo
[ "$P" = "$P2" ] || { echo "As senhas não conferem." >&2; exit 1; }
[ ${#P} -ge 12 ] || { echo "Senha curta demais." >&2; exit 1; }
H=$(printf '%s\n' "$P" | docker compose exec -T caddy caddy hash-password)
printf 'basic_auth {\n\t%s %s\n}\n' "$U" "$H" > acesso-staging.caddy.novo
mv acesso-staging.caddy.novo acesso-staging.caddy
docker compose exec -T caddy caddy reload --config /etc/caddy/Caddyfile
echo "Pronto: staging protegido com o usuário '$U'."
