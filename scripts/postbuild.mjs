// Nach dem Astro-Build: PHP-API + Datendateien nach dist/api kopieren und .htaccess mit Security-Headern schreiben.
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const dist = path.join(root, 'dist');
const api = path.join(root, 'api');
const ziel = path.join(dist, 'api');
const site = JSON.parse(fs.readFileSync(path.join(root, 'src/data/site.json'), 'utf8'));

if (!fs.existsSync(path.join(api, 'vendor/autoload.php'))) {
  console.error('[postbuild] api/vendor fehlt – zuerst "composer install --no-dev" im Ordner api/ ausführen.');
  process.exit(1);
}

const auslassen = new Set(['config.php', 'composer.json', 'composer.lock', 'storage']);
fs.rmSync(ziel, { recursive: true, force: true });
fs.cpSync(api, ziel, {
  recursive: true,
  filter: (src) => {
    const rel = path.relative(api, src);
    if (!rel) return true;
    const first = rel.split(path.sep)[0];
    if (auslassen.has(first)) return false;
    // Beispiele/Doku von PHPMailer nicht ausliefern
    if (/vendor[\\/]phpmailer[\\/]phpmailer[\\/](examples|docs|language)/.test(rel)) return false;
    return true;
  },
});
fs.mkdirSync(path.join(ziel, 'storage'), { recursive: true });
fs.copyFileSync(path.join(api, 'storage/.htaccess'), path.join(ziel, 'storage/.htaccess'));
fs.mkdirSync(path.join(ziel, 'data'), { recursive: true });
for (const f of ['site', 'funnel', 'stellen']) {
  fs.copyFileSync(path.join(root, `src/data/${f}.json`), path.join(ziel, 'data', `${f}.json`));
}
fs.writeFileSync(path.join(ziel, 'data/.htaccess'), 'Require all denied\nDeny from all\n');
fs.writeFileSync(path.join(ziel, 'vendor/.htaccess'), 'Require all denied\nDeny from all\n');
fs.writeFileSync(path.join(ziel, 'lib/.htaccess'), 'Require all denied\nDeny from all\n');

// Security-Header (Apache). Plausible nur erlauben, wenn konfiguriert.
const plausible = site.analytics.plausible_domain ? new URL(site.analytics.plausible_src).origin : '';
const csp = [
  "default-src 'self'",
  `script-src 'self'${plausible ? ' ' + plausible : ''}`,
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data:",
  "font-src 'self'",
  `connect-src 'self'${plausible ? ' ' + plausible : ''}`,
  "form-action 'self'",
  "frame-ancestors 'none'",
  "base-uri 'self'",
  "object-src 'none'",
].join('; ');
fs.writeFileSync(path.join(dist, '.htaccess'), `# Automatisch erzeugt von scripts/postbuild.mjs
Options -Indexes
ErrorDocument 404 ${process.env.BASE_PATH ?? '/karriere'}/404.html

<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{HTTPS} !=on
  RewriteCond %{HTTP:X-Forwarded-Proto} !https
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</IfModule>

<IfModule mod_headers.c>
  Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
  Header always set X-Content-Type-Options "nosniff"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
  Header always set Permissions-Policy "camera=(self), geolocation=(), microphone=(), interest-cohort=()"
  Header always set Content-Security-Policy "${csp}"
  <FilesMatch "\\.(avif|webp|jpg|png|svg|css|js)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
  <FilesMatch "\\.html$">
    Header set Cache-Control "public, max-age=300"
  </FilesMatch>
</IfModule>

<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml application/xml text/plain
</IfModule>

# Besetzte Stellen: optional mit 410 beantworten, z. B.
# Redirect gone ${process.env.BASE_PATH ?? '/karriere'}/pka/
`);
console.log('[postbuild] API, Daten und .htaccess nach dist/ geschrieben.');
