/* Run with node scripts/build-display-preferences.cjs after adding UI translations.
 * Marks literal template copy; never marks arbitrary database/user text.
 * Derives dark overrides for existing fixed neutral CSS colors.
 */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const context = { window: {} };
vm.runInNewContext(fs.readFileSync(path.join(root, 'public/js/translations.js'), 'utf8'), context);
const catalog = context.window.ViracTranslations;
const decode = text => text.replace(/&amp;/g, '&').replace(/&mdash;/g, '—').replace(/&larr;/g, '←').replace(/&rarr;/g, '→').replace(/&#039;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ');
function files(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
    const target = path.join(directory, entry.name);
    return entry.isDirectory() ? files(target) : [target];
  });
}
const templates = [...files(path.join(root, 'views')), ...files(path.join(root, 'includes')),
  ...fs.readdirSync(root).filter(file => file.endsWith('.php')).map(file => path.join(root, file))].filter(file => file.endsWith('.php'));
let changed = 0;
for (const file of templates) {
  if (/reports[\\/]print\.php|solo-parent-id-card\.php/.test(file)) continue;
  const original = fs.readFileSync(file, 'utf8');
  const php = [];
  let content = original.replace(/<\?(?:php|=)[\s\S]*?\?>/g, block => {
    php.push(block);
    return `__VIRAC_PHP_${php.length - 1}__`;
  });
  // Track parents so copy after an icon, link or PHP block is marked too.
  // Only literal text in the catalog qualifies; PHP/user data is never inferred.
  const tokens = content.split(/(<[^>]+>)/g);
  const stack = [];
  const marked = new Set();
  tokens.forEach((token, index) => {
    const closing = token.match(/^<\/([\w-]+)/);
    const opening = token.match(/^<([a-z][\w-]*)\b/i);
    if (closing) {
      const position = stack.findLastIndex(item => item.tag === closing[1].toLowerCase());
      if (position >= 0) stack.length = position;
    } else if (opening) {
      const tag = opening[1].toLowerCase();
      if (!/^(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)$/.test(tag) && !token.endsWith('/>')) stack.push({ tag, index });
    } else if (stack.length && !stack.some(item => /^(script|style|textarea|svg)$/.test(item.tag))) {
      const parent = stack[stack.length - 1];
      const segments = token.split(/__VIRAC_PHP_\d+__/g);
      const interfaceExpression = /(?:\$(?:c|category|doc|item|it|guide|d|stage|s|step|group)\['(?:name|label|title|detail|description|basis|where)'\]|\$(?:app|application|latestApplication|a)\['(?:status|category_name)'\]|\$(?:sectionTitle|welcomeText|label|text|title)\b|\$errors\[)/;
      const publicTrackingCopy = /\$(?:result|idSummary|event|appointment)\['(?:status|title|instruction|headline|received|validity|renewal|label)'\]/;
      const guideCopy = /\$(?:item|step|note)\b|\$office\['purpose'\]/;
      const hasLiteralInterfaceCopy = [...token.matchAll(/__VIRAC_PHP_(\d+)__/g)].some(match => {
        const expression = php[Number(match[1])];
        return expression.startsWith('<?=') && [...expression.matchAll(/(['"])([^'"\r\n]+)\1/g)]
          .some(literal => Object.hasOwn(catalog, decode(literal[2])));
      });
      const hasInterfaceCopy = /views[\\/](?:applicant|auth|home)[\\/]|includes[\\/]portal[\\/]|views[\\/]partials[\\/](?:emergency-contact-fields|requirements-preparation)/.test(file) &&
        [...token.matchAll(/__VIRAC_PHP_(\d+)__/g)].some(match => {
          const expression = php[Number(match[1])];
          return interfaceExpression.test(expression) || publicTrackingCopy.test(expression) ||
            (/requirements[\\/]index\.php$/.test(file) && guideCopy.test(expression));
        });
      if ((segments.some(text => Object.hasOwn(catalog, decode(text.trim()))) || hasInterfaceCopy || hasLiteralInterfaceCopy) &&
          !(parent.tag === 'option' && !/\bvalue\s*=/.test(tokens[parent.index]))) marked.add(parent.index);
    }
  });
  marked.forEach(index => {
    if (!/\bdata-i18n(?:\s|=|>)/.test(tokens[index])) tokens[index] = tokens[index].replace(/>$/, ' data-i18n>');
  });
  content = tokens.join('');
  // Mark only literal accessibility labels and placeholders known to the catalog.
  content = content.replace(/<([a-z][\w-]*)(\s[^<>]*?)?>/gi, (match, tag, attributes = '') => {
    if (/data-i18n-attrs=/.test(attributes)) return match;
    const names = [...attributes.matchAll(/\b(aria-label|title|placeholder)="([^"<>]*)"/g)]
      .filter(item => Object.hasOwn(catalog, decode(item[2]))).map(item => item[1]);
    return names.length ? `<${tag}${attributes} data-i18n-attrs="${names.join(' ')}">` : match;
  });
  content = content.replace(/__VIRAC_PHP_(\d+)__/g, (match, index) => php[Number(index)]);
  if (content !== original) { fs.writeFileSync(file, content); changed++; }
}
const isNeutral = hex => {
  let color = hex.slice(1);
  if (color.length === 3) color = color.split('').map(c => c + c).join('');
  if (color.length !== 6) return null;
  const rgb = color.match(/../g).map(c => parseInt(c, 16));
  const max = Math.max(...rgb), min = Math.min(...rgb);
  return { light: min >= 180 && max - min <= 85, dark: max <= 170 };
};
let rules = [];
for (const file of files(path.join(root, 'public/css'))) {
  if (!file.endsWith('.css') || /preferences/.test(file)) continue;
  const css = fs.readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
  for (const match of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    const selector = match[1].trim();
    if (selector.startsWith('@') || selector.includes('%') || /solo-parent-id-card|spid-|qr-code|qr-image|slip-qr|applicant-id-qr|id-card-preview|verification-id-/.test(selector)) continue;
    const declarations = [];
    for (const item of match[2].split(';')) {
      const split = item.indexOf(':');
      if (split < 0) continue;
      const property = item.slice(0, split).trim();
      const value = item.slice(split + 1).trim();
      if (!/^(background|background-color|color|border(?:-color|-top|-bottom|-left|-right)?|--bs-table-bg|--bs-table-border-color)$/.test(property)) continue;
      let replaced = false;
      const semanticValue = /^(background|background-color)$/.test(property) ? value.replace(/var\(--(navy|red)\)/g, (match, token) => {
        replaced = true;
        return token === 'navy' ? '#244f89' : '#9e303b';
      }) : value;
      const next = semanticValue.replace(/#[0-9a-f]{3,8}\b/gi, hex => {
        const neutral = isNeutral(hex);
        if (!neutral) return hex;
        if (neutral.light && property !== 'color') { replaced = true; return property.startsWith('border') || property.includes('border-color') ? 'var(--border)' : 'var(--theme-card)'; }
        if (neutral.dark && property === 'color') { replaced = true; return 'var(--ink)'; }
        return hex;
      });
      if (replaced) declarations.push(`${property}: ${next.replace(/\s*!important/g, '')} !important`);
    }
    if (declarations.length) {
      // Commas inside :is() and :has() are not selector separators.
      const selectors = selector.split(/,(?![^()]*\))/).map(s => `html[data-theme="dark"] ${s.trim()}`).join(', ');
      rules.push(`${selectors} { ${declarations.join('; ')}; }`);
    }
  }
}
fs.writeFileSync(path.join(root, 'public/css/preferences-surfaces.css'), '/* Generated by scripts/build-display-preferences.cjs. */\n@media screen {\n' + rules.join('\n') + '\n}\n');
console.log(`Marked interface copy in ${changed} templates; generated ${rules.length} dark surface rules.`);
