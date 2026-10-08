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
| 1 | Núcleo 1.0.0 + ambiente local + testes base | em curso |
| 2 | Assistente de atualização | pendente |
| 3 | PWA + offline + tokens de app | pendente |
| 4 | Recursos do telemóvel | pendente |
| 5 | Melhorias web | pendente |
| 6 | Capacitor | pendente |
| 7 | Testes completos | pendente |
| 8 | Pacote e guias | pendente |

## Diário
- 2026-10-08: ambiente local montado (MariaDB 10.11, PHP 8.3, Apache 2.4 + mod_php 8.3, Playwright 1.56 + Chromium).
