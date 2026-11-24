(function () {
  var API = 'https://psgc.gitlab.io/api';
  var reg = document.querySelector('#profile-dialog select[name="region"]');
  var prov = document.querySelector('#profile-dialog select[name="province"]');
  var city = document.querySelector('#profile-dialog select[name="municipality"]');
  if (!reg || !prov || !city) return;

  var cache = {};
  function get(path) {
    if (cache[path]) return cache[path];
    cache[path] = fetch(API + path).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
    return cache[path];
  }

  function label(it) {   // "Region III" has a long name in a separate field
    return it.regionName ? it.name + ' (' + it.regionName + ')' : it.name;
  }
  function fill(sel, items, keep, placeholder) {
    sel.innerHTML = '';
    sel.appendChild(new Option(placeholder || '', ''));
    var found = false;
    items.forEach(function (it) {
      var text = label(it);
      var o = new Option(text, text);
      o.dataset.code = it.code;
      if (keep && text.toLowerCase() === keep.toLowerCase()) { o.selected = true; found = true; }
      sel.appendChild(o);
    });
    if (keep && !found) {                    // an old free-typed value: keep it until the user picks another
      var o2 = new Option(keep, keep); o2.selected = true; sel.appendChild(o2);
    }
    sel.disabled = false;
  }
  function code(sel) {
    var o = sel.options[sel.selectedIndex];
    return o ? o.dataset.code || '' : '';
  }
  function byName(a, b) { return a.name.localeCompare(b.name); }
  function clear(sel, text) { sel.innerHTML = ''; sel.appendChild(new Option(text || '', '')); }

  function loadProvinces(keep) {
    var c = code(reg);
    clear(prov); clear(city);
    if (!c) return Promise.resolve();
    return get('/regions/' + c + '/provinces/').then(function (list) {
      if (list.length) {                      // normal region
        fill(prov, list.sort(byName), keep, 'Select province');
        return loadCities(keepCity);
      }
      // Metro Manila (NCR) has no provinces: show the region itself and list its cities directly
      var nm = reg.value.replace(/\s*\(.*$/, '');
      clear(prov); prov.appendChild(new Option(nm, nm)); prov.selectedIndex = 1; prov.disabled = false;
      return get('/regions/' + c + '/cities-municipalities/').then(function (cs) {
        fill(city, cs.sort(byName), keepCity, 'Select city / municipality');
      });
    });
  }
  var keepCity = '';
  function loadCities(keep) {
    var c = code(prov);
    clear(city);
    if (!c) return Promise.resolve();
    return get('/provinces/' + c + '/cities-municipalities/').then(function (list) {
      fill(city, list.sort(byName), keep, 'Select city / municipality');
    });
  }

  function fallback() {                       // API unreachable -> plain text boxes (old behaviour)
    [reg, prov, city].forEach(function (sel) {
      var inp = document.createElement('input');
      inp.type = 'text'; inp.name = sel.name; inp.value = sel.value; inp.autocomplete = 'off';
      sel.replaceWith(inp);
    });
  }

  var start = { r: reg.value, p: prov.value, c: city.value };
  [reg, prov, city].forEach(function (s) { s.disabled = true; });

  get('/regions/').then(function (regions) {
    fill(reg, regions, start.r, 'Select region');
    keepCity = start.c;
    return loadProvinces(start.p);
  }).then(function () {
    keepCity = '';
  }).catch(function () { fallback(); });

  reg.addEventListener('change', function () { keepCity = ''; loadProvinces(''); });
  prov.addEventListener('change', function () { loadCities(''); });
})();