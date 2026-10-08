#!/usr/bin/env bash
# Gera o pacote de distribuição do SGI (sem Node/Composer no servidor; tudo pronto a extrair no cPanel).
# Saída em dist/v<versão>/:
#   sgi-facasi-v<versão>-sistema-parteNdeM.zip  (cada parte < 30 MB; todas com a pasta raiz sgi/)
#   CODIGO-INSTALACAO.txt                       (entregar SEPARADAMENTE; o pacote só tem o hash)
#   sgi-facasi-v<versão>-documentacao.zip, sgi-facasi-v<versão>-app-capacitor.zip, SHA256SUMS.txt
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
V="$(tr -d '[:space:]' < "$ROOT/sgi/VERSION")"
OUT="$ROOT/dist/v$V"
ST="$OUT/staging"
LIMITE=$((20 * 1024 * 1024))
rm -rf "$OUT"; mkdir -p "$ST/sgi"
php "$ROOT/tools/sync-modelos.php"
(cd "$ROOT/sgi" && tar --exclude=./config.php --exclude=./storage --exclude=./instalar/.instalado --exclude=./instalar/codigo.hash --exclude=./dev-router.php -cf - .) | (cd "$ST/sgi" && tar xf -)
# versão do service worker (força a atualização da aplicação instalada nos telemóveis)
sed -i "s/const VERSAO = 'sgi-[^']*'/const VERSAO = 'sgi-$V-$(date +%Y%m%d%H%M)'/" "$ST/sgi/sw.js"
php "$ROOT/tools/gen-install-code.php" "$OUT/CODIGO-INSTALACAO.txt" "$ST/sgi" > /dev/null
mapfile -t PARTES < <(php "$ROOT/tools/manifesto.php" "$ST/sgi" "$LIMITE")
TOTAL=${#PARTES[@]}
cd "$ST"
for linha in "${PARTES[@]}"; do
  n="${linha%%$'\t'*}"
  z="$OUT/sgi-facasi-v$V-sistema-parte${n}de${TOTAL}.zip"
  php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach($m["ficheiros"] as $f=>$i){ if($i["parte"]==(int)$argv[2]) echo "sgi/$f\n"; } if((int)$argv[2]===1) echo "sgi/PACOTE.json\n";' "$ST/sgi/PACOTE.json" "$n" | zip -q -X -@ "$z"
done
cd "$ROOT"
(cd "$ROOT" && zip -q -r -X "$OUT/sgi-facasi-v$V-documentacao.zip" docs)
(cd "$ROOT" && zip -q -r -X "$OUT/sgi-facasi-v$V-app-capacitor.zip" capacitor -x 'capacitor/node_modules/*' 'capacitor/android/.gradle/*' 'capacitor/android/build/*' 'capacitor/android/app/build/*')
rm -rf "$ST"
(cd "$OUT" && sha256sum *.zip > SHA256SUMS.txt)
ls -la "$OUT"
for z in "$OUT"/*.zip; do s=$(stat -c %s "$z"); [ "$s" -lt $((30 * 1024 * 1024)) ] || { echo "ERRO: $z tem $s bytes (>= 30 MB)"; exit 1; }; done
echo "OK: todas as partes têm menos de 30 MB."
