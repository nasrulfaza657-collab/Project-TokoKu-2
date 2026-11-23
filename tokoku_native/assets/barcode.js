/* Code 128 -> SVG (tanpa library luar, bisa dipakai offline).
   Pakai: <svg class="bc" data-code="2000000000012"></svg> lalu panggil TokoKuBarcode.render(). */
(function (root) {
  var P = ["212222","222122","222221","121223","121322","131222","122213","122312","132212","221213",
  "221312","231212","112232","122132","122231","113222","123122","123221","223211","221132",
  "221231","213212","223112","312131","311222","321122","321221","312212","322112","322211",
  "212123","212321","232121","111323","131123","131321","112313","132113","132311","211313",
  "231113","231311","112133","112331","132131","113123","113321","133121","313121","211331",
  "231131","213113","213311","213131","311123","311321","331121","312113","312311","332111",
  "314111","221411","431111","111224","111422","121124","121421","141122","141221","112214",
  "112412","122114","122411","142112","142211","241211","221114","413111","241112","134111",
  "111242","121142","121241","114212","124112","124211","411212","421112","421211","212141",
  "214121","412121","111143","111341","131141","114113","114311","411113","411311","113141",
  "114131","311141","411131","211412","211214","211232","2331112"];

  function encode(text) {
    var v = [], i;
    if (/^\d+$/.test(text) && text.length % 2 === 0) {          // Code C: 2 digit per simbol
      v.push(105);
      for (i = 0; i < text.length; i += 2) v.push(parseInt(text.substr(i, 2), 10));
    } else {                                                      // Code B: ASCII 32..127
      v.push(104);
      for (i = 0; i < text.length; i++) {
        var c = text.charCodeAt(i);
        if (c < 32 || c > 126) throw new Error("Karakter tidak didukung: " + text.charAt(i));
        v.push(c - 32);
      }
    }
    var sum = v[0];
    for (i = 1; i < v.length; i++) sum += v[i] * i;
    v.push(sum % 103);
    v.push(106);
    return v;
  }

  function modules(text) {            // deret lebar bar/spasi (mulai dari bar)
    var out = [];
    encode(text).forEach(function (s) {
      P[s].split("").forEach(function (d) { out.push(parseInt(d, 10)); });
    });
    return out;
  }

  function svgInner(text, quiet) {
    var m = modules(text), x = quiet, rects = "", bar = true;
    m.forEach(function (w) {
      if (bar) rects += '<rect x="' + x + '" y="0" width="' + w + '" height="60"/>';
      x += w; bar = !bar;
    });
    return { w: x + quiet, html: rects };
  }

  function render(scope) {
    (scope || document).querySelectorAll("svg.bc[data-code]").forEach(function (el) {
      try {
        var r = svgInner(el.getAttribute("data-code"), 10);
        el.setAttribute("viewBox", "0 0 " + r.w + " 60");
        el.setAttribute("preserveAspectRatio", "none");
        el.setAttribute("shape-rendering", "crispEdges");
        el.setAttribute("fill", "#000");
        el.innerHTML = r.html;
      } catch (e) { el.outerHTML = "<span>" + e.message + "</span>"; }
    });
  }

  root.TokoKuBarcode = { encode: encode, modules: modules, render: render, _patterns: P };
  if (typeof document !== "undefined") {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", function () { render(); });
    else render();
  }
})(typeof window !== "undefined" ? window : globalThis);
