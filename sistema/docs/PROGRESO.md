# PROGRESO — SGI FACASI (web + app)

Registo de progresso por etapas. Se a sessão for interrompida, continuar a partir da última etapa marcada como "em curso".

## Contexto encontrado (2026-10-08)
- O repositório **não contém** o pacote `sgi-purenova-v1.0.0-sistema.zip` mencionado no pedido; a PURENOVA era apenas um exemplo.
  Decisão do utilizador: construir o SGI para a **FACASI** com o material existente neste repositório.
- Material existente: 124 HTML documentais (PDF-01: 86, Excel-trinormas: 28, Registros: índices) e 52 páginas do manual (`html/`),
  todos em português (pt-AO); PDFs de normas, manuais, auditorias e certificados; DOC/DOCX de RRHH.
- Vários modelos tinham botões de "guardar no navegador" (localStorage) e "Guardar (Simular)": no SGI esses botões são
  substituídos pelo fluxo real (Guardar = rascunho MySQL; Descarregar = finalizar).
- `polyfill.io` (CDN comprometido em 2024) é removido dos modelos na sincronização.
- Não existia versão 1.0.0 instalada. O núcleo foi escrito como **esquema 1.0.0** e as novidades móveis como **1.1.0**,
  para que o assistente de atualização seja testado de verdade (1.0.0 → 1.1.0).
- Idioma da interface e documentos: **português (Angola)**, coerente com os modelos da FACASI.

## Arquitetura
- `sistema/sgi/` → pasta raiz do pacote (vai para `public_html/sgi/`): PHP 7.4–8.4 sem framework, MySQL/MariaDB (PDO),
  SPA em JavaScript puro (sem build), PWA (manifest + service worker), assistente de instalação (`instalar/`) e de atualização (`atualizar/`).
- `api.php/<rota>` → API REST JSON (sessão + CSRF no navegador; tokens Bearer de curta duração + renovação para o app).
- `modelo.php` → serve modelos/registos HTML dentro de iframe com "ponte" de campos (captura/restauração de valores).
- `storage/` → ficheiros por organização (`org_<id>/...`), negado por `.htaccess`.
- `sistema/tests/` → Playwright E2E (`run-all.sh`), `sistema/tools/` → empacotamento, `sistema/capacitor/` → app nativo.

## Etapas
| # | Etapa | Estado |
|---|-------|--------|
| 1 | Núcleo 1.0.0 + ambiente local + testes base | concluído |
| 2 | Assistente de atualização | concluído (T23) |
| 3 | PWA + offline + tokens de app | concluído (T15–T18, T21) |
| 4 | Recursos do telemóvel | concluído (T19, T20) |
| 5 | Melhorias web | concluído (T25–T30) |
| 6 | Capacitor | projeto Android/iOS criado; APK/AAB não compilável neste ambiente (dl.google.com 403) |
| 7 | Testes completos | em curso (php -S: aceitação + ida e volta 165×2; Apache: em curso) |
| 8 | Pacote e guias | em curso (documentação HTML escrita; empacotamento por gerar) |

## Diário
- 2026-10-08: ambiente local montado (MariaDB 10.11, PHP 8.3, Apache 2.4 + mod_php 8.3, Playwright 1.56 + Chromium).
- Núcleo, API, SPA, ponte de modelos, service worker, instalador e atualizador escritos e testados.
- Primeira bateria: 22/22 (T01–T21, T23) no php -S; ida e volta 330/330 (165 documentos × 390/1280 px).
- Âmbito normativo por organização: FACASI = ISO 9001 + ISO 45001 (fornecido pelo relatório de validação original);
  cláusulas exclusivas da ISO 14001 marcadas como "não aplicável" com justificação.
- Estimativa de campos do catálogo corrigida (ignorava CSS/JS) e teste de coerência formulário/consulta acrescentado.
- Projeto Capacitor 8.5.3 (android/ios) com permissões de câmara/localização e WKAppBoundDomains.
- Push para o GitHub recusado (403: a app Claude não tem acesso de escrita ao repositório). Commits locais mantidos.
