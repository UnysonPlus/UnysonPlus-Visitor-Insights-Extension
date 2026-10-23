// Build tool, not loaded by WordPress: `npm i world-atlas@2.0.2 topojson-client@3.1.0
// i18n-iso-countries@7.11.3`, then `node build-world.cjs world.json`.
//
// Builds the Visitor Insights world outline: Natural Earth 1:110m countries
// (public domain, via world-atlas, ISC), equirectangular, 1000 wide, latitude
// 84N..58S, ISO 3166-1 alpha-2 keys, Antarctica left out.
const topo = require('world-atlas/countries-110m.json');
const { feature } = require('topojson-client');
const iso = require('i18n-iso-countries');
const N = 84, S = -58, W = 1000, H = Math.round((N - S) / 360 * W);
const x = (lon) => ((lon + 180) / 360 * W);
const y = (lat) => ((N - Math.max(S, Math.min(N, lat))) / 360 * W);
const out = { w: W, h: H, n: N, s: S, c: {}, names: {} };
const SHORT = { US: 'United States', GB: 'United Kingdom', RU: 'Russia', KR: 'South Korea', KP: 'North Korea', IR: 'Iran', SY: 'Syria', VN: 'Vietnam', LA: 'Laos', BO: 'Bolivia', VE: 'Venezuela', TZ: 'Tanzania', MD: 'Moldova', CD: 'DR Congo', CG: 'Congo', TW: 'Taiwan', XK: 'Kosovo', PS: 'Palestine', FK: 'Falkland Islands', MK: 'North Macedonia', CI: 'Côte d’Ivoire' };
for (const f of feature(topo, topo.objects.countries).features) {
  let cc = iso.numericToAlpha2(f.id) || null;
  if (!cc) { const n = f.properties.name; cc = n === 'Kosovo' ? 'XK' : n === 'N. Cyprus' ? 'CY' : n === 'Somaliland' ? 'SO' : null; }
  if (!cc || cc === 'AQ') continue;
  const polys = f.geometry.type === 'Polygon' ? [f.geometry.coordinates] : f.geometry.coordinates;
  let d = '';
  for (const poly of polys) for (const ring of poly) {
    let prev = null;
    ring.forEach(([lon, lat], i) => {
      const jump = prev !== null && Math.abs(lon - prev) > 180;
      d += (i === 0 || jump ? 'M' : 'L') + x(lon).toFixed(1) + ',' + y(lat).toFixed(1);
      prev = lon;
    });
    d += 'Z';
  }
  out.names[cc] = SHORT[cc] || iso.getName(cc, 'en') || f.properties.name;
  out.c[cc] = (out.c[cc] || '') + d.replace(/\.0(?=[,LMZ])/g, '');
}
require('fs').writeFileSync(process.argv[2], JSON.stringify(out));
console.log(Object.keys(out.c).length, 'countries', W + 'x' + H);
