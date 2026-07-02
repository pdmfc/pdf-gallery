# PDF Gallery (`pdmfc/pdf-gallery`)

Pacote Laravel para galeria de PDFs com pré-visualização (PDF.js), miniaturas no servidor, upload, seleção múltipla, reordenação, junção, gravação na galeria e impressão.

Stack: **Laravel 10/11** · **Inertia** · **Vue 3** · **PDF.js** · **qpdf / Ghostscript / FPDI**

---

## Índice

1. [Funcionalidades](#funcionalidades)
2. [Requisitos](#requisitos)
3. [Instalação (Composer)](#instalação-composer)
4. [Integração frontend (Vite + Vue)](#integração-frontend-vite--vue)
5. [Sessão e autorização](#sessão-e-autorização)
6. [Uso na aplicação](#uso-na-aplicação)
7. [Laravel Nova (campo Vue embutido)](#laravel-nova-campo-vue-embutido)
8. [API HTTP](#api-http)
9. [Configuração (.env)](#configuração-env)
10. [Ferramentas de sistema (merge e miniaturas)](#ferramentas-de-sistema-merge-e-miniaturas)
11. [Kubernetes / OpenShift](#kubernetes--openshift)
12. [Desenvolvimento local](#desenvolvimento-local)
13. [Build e deploy do frontend](#build-e-deploy-do-frontend)
14. [Comando de verificação](#comando-de-verificação)
15. [Storage em produção](#storage-em-produção)
16. [Segurança](#segurança)
17. [Resolução de problemas](#resolução-de-problemas)

---

## Funcionalidades

| Área | Descrição |
|------|-----------|
| Galeria | PDFs por `user_id`, com limite configurável |
| Upload | Múltiplos ficheiros, validação MIME + cabeçalho `%PDF-` |
| Miniaturas | JPEG gerado no servidor (Ghostscript), servido por API |
| Pré-visualização | Painel com PDF.js (páginas, zoom, imprimir, descarregar) |
| Seleção | Checkbox por documento, seleccionar todos, eliminar em lote |
| Ordenação | Arrastar pelos **pontos** à esquerda; barra azul indica a posição |
| Merge | Junta PDFs na **ordem da galeria** (qpdf → Ghostscript → FPDI) |
| Gravar merge | `POST /merge/save` — guarda o PDF unido na galeria |
| Formulário | Modal `PdfGalleryFormModal` com «Usar no formulário» |
| Autorização | Middleware por `user_id` (sessão ou utilizador autenticado) |

---

## Requisitos

### PHP / Laravel

- PHP **8.3+**
- Laravel **10** ou **11**
- [Inertia Laravel](https://inertiajs.com/) + **Vue 3** no projeto host
- Extensões PHP habituais: `fileinfo`, `json`, `mbstring`

### Composer (no projeto host)

```bash
composer require setasign/fpdf setasign/fpdi
```

> **Importante:** não faça `require` do `vendor/autoload.php` completo do package `pdf-gallery` no host — pode trazer dependências incompatíveis (ex.: Laravel 11 dentro do package). O host deve ter **FPDI/FPDF** no seu próprio `vendor/`.

### Node (no projeto host)

```bash
npm install pdfjs-dist
```

Também precisa de: `vue`, `@inertiajs/vue3`, `axios`, `tailwindcss` (para estilos do package).

### Sistema operativo (recomendado)

| Ferramenta | Uso |
|------------|-----|
| **qpdf** | Merge principal (PDFs modernos) |
| **Ghostscript** (`gs`) | Merge alternativo + **miniaturas JPEG** |
| **FPDI** (PHP) | Fallback de merge sem binários externos |

Sem Ghostscript, as miniaturas não são geradas. Sem qpdf/GS, o merge pode ainda funcionar via FPDI (com limitações).

---

## Instalação (Composer)

### 1. Repositório path (desenvolvimento)

No `composer.json` do host:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../pdf-gallery",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "pdmfc/pdf-gallery": "@dev",
    "setasign/fpdf": "^1.8",
    "setasign/fpdi": "^2.6"
  }
}
```

```bash
composer require pdmfc/pdf-gallery:@dev
composer require setasign/fpdf setasign/fpdi
```

### 2. Publicar configuração

```bash
php artisan vendor:publish --tag=pdf-gallery-config
php artisan vendor:publish --tag=pdf-gallery-nova   # opcional — exemplo Nova
```

Opcional:

```bash
php artisan vendor:publish --tag=pdf-gallery-deployment   # exemplo .env para K8s
php artisan vendor:publish --tag=pdf-gallery-demo-routes  # rota demo
```

O `PdfGalleryServiceProvider` regista-se automaticamente via `composer.json` → `extra.laravel.providers`.

---

## Integração frontend (Vite + Vue)

### 1. Symlinks dos assets Vue

```bash
mkdir -p resources/js/vendor/pdf-gallery
cd resources/js/vendor/pdf-gallery

ln -sf ../../../../vendor/pdmfc/pdf-gallery/src/Resources/js/Components Components
ln -sf ../../../../vendor/pdmfc/pdf-gallery/src/Resources/js/Pages Pages
ln -sf ../../../../vendor/pdmfc/pdf-gallery/src/Resources/js/composables composables
ln -sf ../../../../vendor/pdmfc/pdf-gallery/src/Resources/js/http http
ln -sf ../../../../vendor/pdmfc/pdf-gallery/src/Resources/css css
```

### 2. `vite.config.js`

Permitir leitura do package e aliases (ajuste o caminho `pdfGalleryRoot`):

```js
import path from 'path';

const pdfGalleryRoot = path.resolve(__dirname, '../../../pdf-gallery');
const pdfGalleryJs = path.resolve(pdfGalleryRoot, 'src/Resources/js');
const pdfGalleryCss = path.resolve(pdfGalleryRoot, 'src/Resources/css');

export default defineConfig({
  server: {
    fs: {
      allow: [path.resolve(__dirname), pdfGalleryRoot],
    },
  },
  resolve: {
    alias: {
      '@pdf-gallery': pdfGalleryJs,
      '@pdf-gallery-css': pdfGalleryCss,
      'pdfjs-dist': path.resolve(__dirname, 'node_modules/pdfjs-dist'),
    },
  },
  optimizeDeps: {
    include: ['pdfjs-dist'],
  },
});
```

### 3. `resources/js/app.js`

```js
import '@pdf-gallery-css/app.css'

const pdfGalleryPages = import.meta.glob('./vendor/pdf-gallery/Pages/**/*.vue')
const pdfGalleryComponents = import.meta.glob('./vendor/pdf-gallery/Components/**/*.vue', {
  eager: true,
})

createInertiaApp({
  resolve: (name) => {
    const hostPages = import.meta.glob('./Pages/**/*.vue')

    if (hostPages[`./Pages/${name}.vue`]) {
      return resolvePageComponent(`./Pages/${name}.vue`, hostPages)
    }

    const pdfPageKey = `./vendor/pdf-gallery/Pages/${name}.vue`
    if (pdfGalleryPages[pdfPageKey]) {
      return resolvePageComponent(pdfPageKey, pdfGalleryPages)
    }

    // … outras páginas do host
  },
  setup({ el, App, props, plugin }) {
    const app = createApp({ render: () => h(App, props) }).use(plugin)

    Object.entries(pdfGalleryComponents).forEach(([path, component]) => {
      const name = path.split('/').pop().replace('.vue', '')
      app.component(name, component.default)
    })

    return app.mount(el)
  },
})
```

### 4. `tailwind.config.js`

```js
content: [
  // …
  './vendor/pdmfc/pdf-gallery/src/Resources/**/*.vue',
  './vendor/pdmfc/pdf-gallery/src/Resources/**/*.js',
  './resources/js/vendor/pdf-gallery/**/*.vue',
  './resources/js/vendor/pdf-gallery/**/*.js',
],
```

### 5. Layout Inertia

O layout deve incluir `@vite`, `@inertiaHead` e meta CSRF:

```blade
<meta name="csrf-token" content="{{ csrf_token() }}">
@vite(['resources/css/app.css', 'resources/js/app.js'])
@inertiaHead
```

---

## Sessão e autorização

As rotas API usam middleware `web` + `EnsurePdfGalleryUserAccess`. O `user_id` pedido tem de corresponder ao utilizador autenticado **ou** ao ID guardado em sessão.

Antes de abrir a galeria ou o formulário:

```php
use PDMFC\PdfGallery\Support\PdfGallerySession;

PdfGallerySession::primeGalleryUser($userId);

return inertia('FormExample', ['userId' => $userId]);
```

Alternativa avançada: callback em `config/pdf-gallery.php`:

```php
'authorization' => [
    'authorize' => fn ($user, string $requestedUserId) => /* bool */,
],
```

---

## Uso na aplicação

### Página dedicada

```php
Route::get('/pdf-gallery', function () {
    $userId = request()->query('userId', auth()->id());
    PdfGallerySession::primeGalleryUser($userId);

    return inertia('PdfGalleryPage', ['userId' => $userId]);
});
```

Ou active `PDF_GALLERY_DEMO_ROUTES=true` e visite `/pdf-gallery?userId=1`.

### Modal em formulário

```vue
<script setup>
import { ref } from 'vue'
import PdfGalleryFormModal from '../vendor/pdf-gallery/Components/PdfGalleryFormModal.vue'

const props = defineProps({ userId: { type: [String, Number], required: true } })

const showPdfGallery = ref(false)
const formPdfFilename = ref('')
const formPdfUrl = ref('')

const applyPdfToForm = (payload) => {
  formPdfFilename.value = payload?.filename || ''
  formPdfUrl.value = payload?.url || ''
}
</script>

<template>
  <button type="button" @click="showPdfGallery = true">Abrir galeria de PDF</button>

  <PdfGalleryFormModal
    v-model:open="showPdfGallery"
    :user-id="userId"
    @use-in-form="applyPdfToForm"
  />
</template>
```

### Evento `use-in-form`

| Cenário | Payload |
|---------|---------|
| PDF único | `{ merged: false, filename, url, filenames: [filename] }` |
| Vários unidos | `{ merged: true, filename: 'documentos-unidos.pdf', url, filenames: [...] }` |

`url` pode ser blob (pré-visualização local) ou URL da API após gravar na galeria.

### Componente embutido

```vue
<PdfGallery :user-id="userId" />
```

Os limites (`maxFiles`, `maxUploadMb`, `mergeMaxFiles`) resolvem-se automaticamente por prop explícita, `Nova.config('pdfGallery')`, props Inertia partilhadas ou valores por defeito.

---

## Laravel Nova (campo Vue embutido)

O modal **`PdfGalleryFormModal`** pode ser usado em campos Nova / Raven **sem** `@inertiajs/vue3` no `package.json` do host. Os limites da galeria resolvem-se por:

1. props `:max-files`, `:max-upload-mb`, `:merge-max-files`
2. `Nova.config('pdfGallery')`
3. props Inertia partilhadas (apps Inertia puras)
4. valores por defeito (100 / 25 / 50)

### PHP — `NovaServiceProvider`

```php
use Laravel\Nova\Nova;

Nova::provideToScript([
    'pdfGallery' => [
        'maxFiles' => (int) config('pdf-gallery.gallery.max_files', 100),
        'maxUploadMb' => (int) config('pdf-gallery.gallery.max_upload_mb', 25),
        'mergeMaxFiles' => (int) config('pdf-gallery.merge.max_files', 50),
    ],
]);
```

Ou publique o exemplo:

```bash
php artisan vendor:publish --tag=pdf-gallery-nova
```

### Vite do host

```javascript
{
  find: '@pdf-gallery',
  replacement: path.resolve(__dirname, 'vendor/pdmfc/pdf-gallery/src/Resources/js'),
},
{
  find: '@pdf-gallery-css',
  replacement: path.resolve(__dirname, 'vendor/pdmfc/pdf-gallery/src/Resources/css'),
},
```

Inclua `pdfjs-dist` nos aliases e `optimizeDeps.include` (ver secção de integração frontend).

### Tailwind

```javascript
'./vendor/pdmfc/pdf-gallery/src/Resources/**/*.{js,vue}',
'./resources/js/vendor/pdf-gallery/**/*.{js,vue}',
```

### `userId`

A prop **`user-id`** é uma chave de armazenamento (`pdfs/tmp/{id}/`), **não** tem de ser `auth()->id()`. Pode ser o ID do registo (ex.: Processo, Fact), desde que use apenas `a-z`, `A-Z`, `0-9`, `_` e `-`.

- Com `userId = auth()->id()`: a API autoriza automaticamente (utilizador Nova autenticado).
- Com ID de registo: defina `authorize` em `config/pdf-gallery.php` ou chame `PdfGallerySession::primeGalleryUser($userId)` antes de abrir o modal.

### Exemplo em campo Nova

```javascript
import { defineAsyncComponent } from 'vue'

const PdfGalleryFormModal = defineAsyncComponent(() =>
  import('@pdf-gallery/Components/PdfGalleryFormModal.vue')
)
```

```vue
<PdfGalleryFormModal
  v-if="showPdfGallery"
  v-model:open="showPdfGallery"
  :user-id="storageId"
  :z-index="10050"
  @use-in-form="onPdfChosen"
/>
```

O evento `@use-in-form` devolve o mesmo payload descrito acima (`filename`, `url`, `filenames`, `merged`).

---

## API HTTP

Todas as rotas sob o prefixo configurável (default `api`). Requerem sessão/cookie válidos.

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET` | `/api/pdf-gallery/documents?user_id=` | Listar documentos (com `thumb_url`, ordem da galeria) |
| `POST` | `/api/pdf-gallery/upload` | Upload (`user_id`, `file`) |
| `GET` | `/api/pdf-gallery/files/{userId}/{filename}` | Servir PDF (inline) |
| `GET` | `/api/pdf-gallery/thumbs/{userId}/{filename}` | Miniatura JPEG |
| `DELETE` | `/api/pdf-gallery/documents` | Eliminar (`user_id`, `filenames[]`) |
| `POST` | `/api/pdf-gallery/reorder` | Reordenar (`user_id`, `filenames[]` — lista completa) |
| `POST` | `/api/pdf-gallery/merge` | Juntar PDFs → resposta `application/pdf` |
| `POST` | `/api/pdf-gallery/merge/save` | Juntar e **gravar** na galeria → JSON com `document` |

### Exemplo: listar

```http
GET /api/pdf-gallery/documents?user_id=1
```

```json
{
  "success": true,
  "documents": [
    {
      "filename": "pdf_1782332532_7f257bf9.pdf",
      "url": "/api/pdf-gallery/files/1/pdf_1782332532_7f257bf9.pdf",
      "thumb_url": "/api/pdf-gallery/thumbs/1/pdf_1782332532_7f257bf9.pdf",
      "path": "pdfs/tmp/1/pdf_1782332532_7f257bf9.pdf",
      "timestamp": 1782332532,
      "page_count": 2,
      "size_bytes": 724384
    }
  ]
}
```

### Exemplo: merge

```http
POST /api/pdf-gallery/merge
Content-Type: application/json

{
  "user_id": 1,
  "filenames": ["a.pdf", "b.pdf"],
  "download": false
}
```

Resposta: binário PDF (`Content-Type: application/pdf`).

### Exemplo: gravar merge

```http
POST /api/pdf-gallery/merge/save
Content-Type: application/json

{
  "user_id": 1,
  "filenames": ["a.pdf", "b.pdf"]
}
```

A ordem de `filenames` é normalizada pela **ordem actual da galeria**.

---

## Configuração (.env)

| Variável | Default | Descrição |
|----------|---------|-----------|
| `PDF_GALLERY_DISK` | `public` | Disco Laravel (`local`, `s3`, …) |
| `PDF_GALLERY_STORAGE_PATH` | `pdfs/tmp` | Pasta base no disco |
| `PDF_GALLERY_ROUTES_PREFIX` | `api` | Prefixo das rotas API |
| `PDF_GALLERY_DEMO_ROUTES` | `false` | Rota demo `/pdf-gallery` |
| `PDF_GALLERY_ENFORCE_USER_OWNERSHIP` | `true` | Exigir sessão/auth por `user_id` |
| `PDF_GALLERY_MAX_FILES` | `100` | Máximo de PDFs por galeria |
| `PDF_GALLERY_MAX_UPLOAD_MB` | `25` | Tamanho máximo por upload |
| `PDF_GALLERY_MERGE_MAX_FILES` | `50` | Máximo de PDFs por junção |
| `PDF_GALLERY_MERGE_MAX_TOTAL_MB` | `200` | Tamanho total máximo na junção |
| `PDF_GALLERY_MERGE_ENGINES` | `qpdf,ghostscript,fpdi` | Motores de merge (por ordem) |
| `PDF_GALLERY_QPDF_BINARY` | *(auto)* | Caminho absoluto do `qpdf` |
| `PDF_GALLERY_GHOSTSCRIPT_BINARY` | *(auto)* | Caminho absoluto do `gs` |
| `PDF_GALLERY_BINARY_SEARCH_PATHS` | `/usr/bin,/usr/local/bin,/opt/homebrew/bin,/bin` | Pastas para auto-detecção |
| `PDF_GALLERY_QR_CODE_ENABLED` | `true` | Botão QR na galeria |
| `PDF_GALLERY_QRCODE_URL` | *(fallback `QRCODE_URL`)* | URL da API QR externa |
| `PDF_GALLERY_QRCODE_API_TOKEN` | *(fallback `QRCODE_API_TOKEN`)* | Bearer token da API QR |
| `PDF_GALLERY_QRCODE_DELIVERY_MODE` | `callback_base64` | Modo de entrega (`callback_base64`, …) |
| `PDF_GALLERY_QRCODE_CALLBACK_URL` | *(rota do package)* | URL absoluta enviada à API QR (ex.: `…/callback/upload-draft-attachments/{draftId}`) |
| `PDF_GALLERY_CALLBACK_PATH` | *(extraído da URL)* | Path da rota POST de callback (ex.: `callback/upload-draft-attachments/{draftId}`) |
| `PDF_GALLERY_CALLBACK_SCOPE_PARAM` | `draftId` | Nome do parâmetro de rota/placeholder (também inferido do path) |
| `PDF_GALLERY_CALLBACK_SCOPE_CONSTRAINT` | *(nenhuma)* | `uuid` para validar o parâmetro como UUID |
| `PDF_GALLERY_CALLBACK_MIDDLEWARE` | `api` | Middleware do callback (sem CSRF) |
| `PDF_GALLERY_BROADCASTING` | `true` | Evento `PdfsUploadedFromMobile` via Reverb/Echo |
| `PDF_GALLERY_CONVERT_ENABLED` | `false` | Aceitar imagem/Word e converter para PDF |
| `PDF_GALLERY_CONVERT_ENGINES` | `ghostscript,libreoffice,gotenberg` | Motores globais (ordem de fallback) |
| `PDF_GALLERY_CONVERT_IMAGE_ENGINES` | `ghostscript` | Motores para imagens |
| `PDF_GALLERY_CONVERT_WORD_ENGINES` | `libreoffice,gotenberg` | Motores para Word/ODT/RTF |
| `PDF_GALLERY_LIBREOFFICE_BINARY` | *(auto)* | `soffice` no container ou host |
| `PDF_GALLERY_GOTENBERG_URL` | — | URL HTTP do Gotenberg (ex.: `http://gotenberg:3000`) |
| `PDF_GALLERY_CONVERT_DEFER` | `true` | Guardar Word/Office original; converter só no merge/impressão |

### Conversão integrada (Word + imagens)

Com `PDF_GALLERY_CONVERT_ENABLED=true`, o package aceita **PDF e Word** no upload. Com `PDF_GALLERY_CONVERT_DEFER=true` (por defeito), o **DOCX aparece na galeria com o nome original** e só é convertido para PDF **ao imprimir** ou **ao juntar** — o utilizador não vê o PDF intermédio.

Motores incluídos no package (sem API externa obrigatória):

| Motor | Tipos | Onde corre |
|-------|-------|------------|
| `ghostscript` | JPG, PNG, WebP, … | Binário no container PHP |
| `libreoffice` | DOC, DOCX, ODT, RTF | Binário no container PHP |
| `gotenberg` | Word | Serviço HTTP separado (recomendado em K8s) |

Distribuição típica em Kubernetes:

- **Pod PHP** — qpdf + ghostscript (imagens + merge + miniaturas)
- **Pod Gotenberg** ou **LibreOffice sidecar** — Word → PDF
- Tudo configurado só com `.env` do package

```env
PDF_GALLERY_CONVERT_ENABLED=true
PDF_GALLERY_CONVERT_WORD_ENGINES=gotenberg
PDF_GALLERY_GOTENBERG_URL=http://gotenberg:3000
```

Verificar: `php artisan pdf-gallery:check-tools`

### QR Code (upload mobile)

Fluxo igual ao **image-editor**: o botão QR pede um código à API externa (`upload-files-api`), que envia ficheiros em base64 para o **callback configurável**.

- `endpoint` — URL absoluta com o placeholder substituído (ex.: `{draftId}`, `{userId}`, …)
- `delivery_mode` — por omissão `callback_base64`
- Com conversão activa, imagens/Word no callback são convertidos para PDF antes de entrarem na galeria
- A galeria actualiza em tempo real via canal privado `pdf-gallery.documents.{scopeId}` (Reverb + Echo)

Exemplo (draft de correspondência):

```env
PDF_GALLERY_QRCODE_CALLBACK_URL=https://app.exemplo.test/callback/upload-draft-attachments/{draftId}
PDF_GALLERY_CALLBACK_SCOPE_CONSTRAINT=uuid
```

O identificador passado ao componente (`userId` na prop Vue) é o valor injectado no placeholder — por exemplo o `draftId`.

Se omitir `PDF_GALLERY_QRCODE_CALLBACK_URL`, usa-se a rota por defeito do package: `callback/upload-draft-attachments/{draftId}`.

Ficheiros de ordem da galeria: `{storage}/{path}/{userId}/.gallery-order.json`

---

## Ferramentas de sistema (merge e miniaturas)

O package usa `CliBinaryResolver` (Symfony `ExecutableFinder`):

| Ambiente | Configuração |
|----------|--------------|
| **Kubernetes / OpenShift** | Caminhos absolutos no ConfigMap |
| **Mac / Linux (dev)** | Omitir `PDF_GALLERY_*_BINARY` → detecção automática |

Motores de merge (por ordem):

1. **qpdf** — rápido, bom para PDFs modernos  
2. **ghostscript** — alternativa robusta  
3. **fpdi** — PHP puro, fallback (PDFs simples)

Miniaturas: **sempre Ghostscript** (`{nome}_thumb.jpg` junto ao PDF).

---

## Kubernetes / OpenShift

### Dockerfile (imagem PHP)

```dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends \
    qpdf \
    ghostscript \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
```

### ConfigMap / Deployment

```yaml
env:
  - name: PDF_GALLERY_QPDF_BINARY
    value: "/usr/bin/qpdf"
  - name: PDF_GALLERY_GHOSTSCRIPT_BINARY
    value: "/usr/bin/gs"
  - name: PDF_GALLERY_MERGE_ENGINES
    value: "qpdf,ghostscript,fpdi"
```

Ver também `stubs/deployment.env.example` (publicável com `--tag=pdf-gallery-deployment`).

### Probe (opcional)

```yaml
livenessProbe:
  exec:
    command: ["php", "artisan", "pdf-gallery:check-tools"]
  initialDelaySeconds: 15
  periodSeconds: 120
```

### OpenShift — notas

- Instale `qpdf` e `ghostscript` **na imagem** (não em runtime).
- Com **várias réplicas**, use storage partilhado (`s3`, NFS, PVC ReadWriteMany) — disco local do pod não é partilhado.
- O processo corre com UID aleatório; `/usr/bin/qpdf` e `/usr/bin/gs` funcionam sem permissões especiais.

---

## Desenvolvimento local

### macOS (Homebrew)

```bash
brew install qpdf ghostscript
```

Não é obrigatório definir `.env` — o resolver encontra `/opt/homebrew/bin/qpdf` e `gs`.

### Verificar

```bash
php artisan pdf-gallery:check-tools
```

Saída esperada: `qpdf OK`, `ghostscript OK`, `fpdi OK`, `miniaturas OK`.

### Laravel + Vite

```bash
# Terminal 1
php artisan serve

# Terminal 2 — desenvolvimento com HMR
npm run dev

# Ou produção local (sem public/hot)
npm run build
```

> Se existir `public/hot`, o Laravel usa o dev server Vite. Apague `public/hot` para forçar assets de `public/build/`.

---

## Build e deploy do frontend

```bash
npm run build
```

Confirme que `public/build/manifest.json` inclui os componentes do package. Após alterações no código Vue do package (symlink), rebuilde sempre.

Checklist deploy:

1. `composer install --no-dev`
2. `php artisan config:cache`
3. `npm ci && npm run build`
4. `php artisan storage:link` (se `PDF_GALLERY_DISK=public`)
5. `php artisan pdf-gallery:check-tools`

---

## Comando de verificação

```bash
php artisan pdf-gallery:check-tools
```

Verifica:

- `qpdf` (se motor activo)
- `ghostscript` (merge + miniaturas)
- `fpdi` (classe PHP)
- Caminhos configurados vs. resolvidos

Exit code `0` = OK; `1` = falta dependência.

---

## Storage em produção

Estrutura por utilizador:

```
{disk}:{path}/{userId}/
  pdf_1234567890_abcd.pdf
  pdf_1234567890_abcd_thumb.jpg
  .gallery-order.json
```

| Cenário | Recomendação |
|---------|--------------|
| 1 réplica | Disco `public` ou `local` + PVC |
| N réplicas | S3 ou volume partilhado |
| URLs | PDFs e thumbs servidos via API autenticada, não URL pública do storage |

---

## Segurança

- Middleware valida `user_id` em todas as operações.
- Nomes de ficheiro sanitizados; rejeição de paths com `..`.
- Upload validado por MIME e cabeçalho `%PDF-`.
- Ficheiros servidos com `Content-Disposition: inline` via rotas protegidas.
- CSRF nas rotas `web` (axios envia `X-CSRF-TOKEN`).

Desactive apenas em ambientes controlados:

```env
PDF_GALLERY_ENFORCE_USER_OWNERSHIP=false
```

---

## Resolução de problemas

| Problema | Solução |
|----------|---------|
| API `403 Não autorizado` | Chame `PdfGallerySession::primeGalleryUser($userId)` na rota da página |
| Miniaturas vazias | Instale Ghostscript; `php artisan pdf-gallery:check-tools` |
| Merge falha | Instale qpdf; confirme `PDF_GALLERY_MERGE_ENGINES` |
| UI não actualiza | Apague `public/hot`; `npm run build`; hard refresh (Cmd+Shift+R) |
| `BadMethodCallException` Laravel | Não carregue `pdf-gallery/vendor/autoload.php` no host |
| FPDI não encontrado | `composer require setasign/fpdf setasign/fpdi` no **host** |
| Binários não encontrados no container | Defina `PDF_GALLERY_QPDF_BINARY` e `PDF_GALLERY_GHOSTSCRIPT_BINARY` |

---

## Licença

MIT — PDMFC
