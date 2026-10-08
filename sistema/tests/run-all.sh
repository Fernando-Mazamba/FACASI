#!/usr/bin/env bash
# Bateria completa de testes E2E do SGI.
#   bash tests/run-all.sh            → php -S e Apache (subpasta /sgi/), incluindo ida e volta de todos os HTML
#   bash tests/run-all.sh php        → só servidor embutido do PHP
#   bash tests/run-all.sh apache     → só Apache + mod_php
#   SGI_SEM_IDA_VOLTA=1 …            → salta os testes longos de ida e volta
# Requisitos locais: PHP 8.x (CLI + mod_php), MariaDB/MySQL, Node 18+, Chromium do Playwright, unzip.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$ROOT/tests"
WORK="${SGI_WORK:-/tmp/sgi-e2e}"
MODO="${1:-tudo}"
DB_USER="${SGI_DB_USER:-sgi}"
DB_PASS="${SGI_DB_PASS:-sgi_dev_pw}"
MYSQL="${SGI_MYSQL:-mysql -uroot}"
RES="$T/results"
mkdir -p "$RES" "$WORK"
export PLAYWRIGHT_BROWSERS_PATH="${PLAYWRIGHT_BROWSERS_PATH:-/opt/pw-browsers}"

log() { printf '\n\033[1;34m== %s\033[0m\n' "$*"; }

preparar_ambiente() {
  log "Preparar ambiente"
  command -v php >/dev/null || { echo "PHP em falta"; exit 1; }
  if ! $MYSQL -e 'SELECT 1' >/dev/null 2>&1; then
    (service mariadb start || service mysql start || (mysqld_safe >/dev/null 2>&1 &)) >/dev/null 2>&1
    for i in $(seq 1 20); do $MYSQL -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
  fi
  $MYSQL -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS'; GRANT ALL ON \`sgi\\_%\`.* TO '$DB_USER'@'localhost'; GRANT ALL ON \`sgi\\_%\`.* TO '$DB_USER'@'127.0.0.1'; FLUSH PRIVILEGES;" || exit 1
  [ -d "$ROOT/node_modules/@playwright/test" ] || (cd "$ROOT" && npm install --no-audit --no-fund)
  php "$ROOT/tools/sync-modelos.php"
  [ -f "$T/fixtures/foto-teste.jpg" ] || php "$T/fixtures/gerar-foto.php" "$T/fixtures/foto-teste.jpg"
}

# instancia <pasta> → cópia limpa do pacote (como acabado de extrair no cPanel)
instancia() {
  local dir="$1"
  rm -rf "$dir"; mkdir -p "$dir/sgi"
  (cd "$ROOT/sgi" && tar --exclude=./config.php --exclude=./storage --exclude=./instalar/.instalado --exclude=./instalar/codigo.hash -cf - .) | (cd "$dir/sgi" && tar xf -)
}

bd_nova() { $MYSQL -e "DROP DATABASE IF EXISTS \`$1\`; CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"; }

correr() {
  # correr <nome> <url-principal> <url-atualizacao> <bd> <pasta-dados> <codigo>
  local nome="$1" url="$2" upd="$3" db="$4" dados="$5" codigo="$6"
  rm -f "$RES/estado-$nome.json"
  local comum=(SGI_URL="$url" SGI_UPD_URL="$upd" SGI_DB="$db" SGI_DB_USER="$DB_USER" SGI_DB_PASS="$DB_PASS" SGI_STORAGE="$dados" SGI_INSTALL_CODE="$codigo" SGI_STATE="$RES/estado-$nome.json" SGI_SERVIDOR="$nome" SGI_RESULTS="$RES/$nome")
  log "[$nome] Testes de aceitação T01–T21, T23, T24"
  (cd "$T" && env "${comum[@]}" SGI_REPORT="$RES/resultado-$nome-aceitacao.json" SGI_ARTEFACTOS="$RES/artefactos-$nome-aceitacao" npx playwright test ${SGI_TESTES:-e2e/0[1-8]*.spec.js e2e/1*.spec.js} --workers=1)
  local r1=$?
  local r2=0
  if [ -z "${SGI_SEM_IDA_VOLTA:-}" ]; then
    log "[$nome] Ida e volta de todos os HTML (390 px e 1280 px)"
    (cd "$T" && env "${comum[@]}" SGI_REPORT="$RES/resultado-$nome-ida-volta.json" SGI_ARTEFACTOS="$RES/artefactos-$nome-ida-volta" npx playwright test e2e/09-ida-volta.spec.js --workers="${SGI_WORKERS:-4}")
    r2=$?
  fi
  node "$T/resumo.mjs" "$nome" "$RES" > "$RES/resumo-$nome.txt"; cat "$RES/resumo-$nome.txt"
  return $(( r1 || r2 ))
}

passagem_php() {
  log "Servidor embutido do PHP ($(php -r 'echo PHP_VERSION;'))"
  local A="$WORK/php-main" U="$WORK/php-upd"
  instancia "$A"; instancia "$U"
  local codigo; codigo=$(php "$ROOT/tools/gen-install-code.php" "" "$A/sgi")
  bd_nova sgi_e2e_php; bd_nova sgi_e2e_php_upd
  rm -rf "$WORK/dados-php" "$WORK/dados-php-upd"
  php "$T/fixtures/seed-1.0.0.php" "$U/sgi" sgi_e2e_php_upd "$DB_USER" "$DB_PASS" "$WORK/dados-php-upd" || return 1
  pkill -f "php -S 127.0.0.1:809[01]" 2>/dev/null; sleep 0.5
  (php -S 127.0.0.1:8090 -t "$A" "$A/sgi/dev-router.php" > "$WORK/php-main.log" 2>&1 &)
  (php -S 127.0.0.1:8091 -t "$U" "$U/sgi/dev-router.php" > "$WORK/php-upd.log" 2>&1 &)
  sleep 1
  correr php "http://127.0.0.1:8090/sgi/" "http://127.0.0.1:8091/sgi/" sgi_e2e_php "$WORK/dados-php" "$codigo"
  local r=$?
  pkill -f "php -S 127.0.0.1:809[01]" 2>/dev/null
  return $r
}

passagem_apache() {
  command -v apache2ctl >/dev/null || { echo "Apache não instalado: passagem ignorada"; return 2; }
  log "Apache + mod_php em subpasta /sgi/ com .htaccess"
  local A="$WORK/apache-main" U="$WORK/apache-upd"
  instancia "$A"; instancia "$U"
  local codigo; codigo=$(php "$ROOT/tools/gen-install-code.php" "" "$A/sgi")
  bd_nova sgi_e2e_apache; bd_nova sgi_e2e_apache_upd
  rm -rf "$WORK/dados-apache" "$WORK/dados-apache-upd"
  php "$T/fixtures/seed-1.0.0.php" "$U/sgi" sgi_e2e_apache_upd "$DB_USER" "$DB_PASS" "$WORK/dados-apache-upd" || return 1
  mkdir -p "$WORK/dados-apache"
  chown -R www-data:www-data "$A" "$U" "$WORK/dados-apache" "$WORK/dados-apache-upd"
  local php_mod; php_mod=$(ls /etc/apache2/mods-available/ | grep -oE '^php8\.[0-9]+' | sort -V | tail -1)
  a2dismod -q mpm_event >/dev/null 2>&1; a2enmod -q mpm_prefork rewrite headers setenvif mime "$php_mod" >/dev/null 2>&1
  cat > /etc/apache2/sites-available/sgi-e2e.conf <<EOF
Listen 8092
Listen 8093
<VirtualHost 127.0.0.1:8092>
  DocumentRoot $A
  <Directory $A>
    AllowOverride All
    Require all granted
  </Directory>
  ErrorLog $WORK/apache-main-error.log
</VirtualHost>
<VirtualHost 127.0.0.1:8093>
  DocumentRoot $U
  <Directory $U>
    AllowOverride All
    Require all granted
  </Directory>
  ErrorLog $WORK/apache-upd-error.log
</VirtualHost>
EOF
  a2ensite -q sgi-e2e >/dev/null 2>&1
  a2dissite -q 000-default >/dev/null 2>&1
  apache2ctl stop >/dev/null 2>&1; sleep 1
  apache2ctl start || { echo "Falha ao iniciar Apache"; return 1; }
  sleep 1
  correr apache "http://127.0.0.1:8092/sgi/" "http://127.0.0.1:8093/sgi/" sgi_e2e_apache "$WORK/dados-apache" "$codigo"
  local r=$?
  apache2ctl stop >/dev/null 2>&1
  return $r
}

preparar_ambiente
R=0
case "$MODO" in
  php) passagem_php || R=1 ;;
  apache) passagem_apache || R=1 ;;
  *) passagem_php || R=1; passagem_apache || R=1 ;;
esac
log "Concluído (código $R). Resumos em $RES/resumo-*.txt"
exit $R
