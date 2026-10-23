/*!
 * 会话同步脚本（CDN 自适应）
 * ------------------------------------------------------------
 * 若站点位于 CDN 之后且 CDN 缓存了 HTML：
 *  1. 页面里的 CSRF 令牌可能是陈旧的 → 本脚本在加载时获取当前会话的最新令牌，
 *     回填到所有 POST 表单，保证提交不被拒绝；
 *  2. 页面可能来自其他会话的缓存 → 通过会话指纹比对检测到后，
 *     立即用带随机参数的地址绕过缓存重新加载。
 * 无需对 CDN 做任何配置。
 */
(function () {
  var ENDPOINT = "/api/csrf_token.php";

  function fillForms(token) {
    var forms = document.querySelectorAll("form");
    for (var i = 0; i < forms.length; i++) {
      var form = forms[i];
      if ((form.method || "get").toLowerCase() !== "post") continue;
      var input = form.querySelector('input[name="csrf_token"]');
      if (!input) {
        input = document.createElement("input");
        input.type = "hidden";
        input.name = "csrf_token";
        form.appendChild(input);
      }
      input.value = token;
    }
  }

  function apply(data) {
    if (!data) return;
    if (data.token) {
      window.__DSH_CSRF__ = data.token;
      fillForms(data.token);
    }
    if (window.__DSH_SESSION__ && data.fingerprint && data.fingerprint !== window.__DSH_SESSION__) {
      var sep = location.search ? "&" : "?";
      location.replace(location.pathname + location.search + sep + "_r=" + Date.now());
    }
  }

  function sync() {
    var url = ENDPOINT + "?_=" + Date.now();
    if (window.fetch) {
      fetch(url, { cache: "no-store", credentials: "same-origin" })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(apply)
        .catch(function () {});
      return;
    }
    var xhr = new XMLHttpRequest();
    xhr.open("GET", url, true);
    xhr.onload = function () {
      if (xhr.status >= 200 && xhr.status < 300) {
        try { apply(JSON.parse(xhr.responseText)); } catch (e) {}
      }
    };
    xhr.send();
  }

  /* 提交前确保使用最新令牌（覆盖旧的隐藏值） */
  document.addEventListener("submit", function (event) {
    var form = event.target;
    if (!form || !form.method || form.method.toLowerCase() !== "post") return;
    if (!window.__DSH_CSRF__) return;
    var input = form.querySelector('input[name="csrf_token"]');
    if (!input) {
      input = document.createElement("input");
      input.type = "hidden";
      input.name = "csrf_token";
      form.appendChild(input);
    }
    input.value = window.__DSH_CSRF__;
  }, true);

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", sync);
  } else {
    sync();
  }
})();