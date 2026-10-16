/* HomaCRM — ورود و ثبت‌نام با پیامک. بی‌وابستگی (بدون jQuery). */
(function () {
  'use strict';
  var cfg = window.HomaCRMLogin || {};
  function post(action, data) {
    var body = new URLSearchParams(Object.assign({ action: action, nonce: cfg.nonce }, data));
    return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json().catch(function () { return { success: false, data: {} }; }); })
      .catch(function () { return { success: false, data: {} }; })
      .then(function (r) { if (!r.success && !(r.data && r.data.message)) { r.data = { message: cfg.i18n.error }; } return r; });
  }
  function init(box) {
    var $ = function (s) { return box.querySelector(s); };
    var mobile = $('.homacrm-mobile'), code = $('.homacrm-code'), msg = $('.homacrm-msg');
    var stepM = $('.homacrm-step-mobile'), stepC = $('.homacrm-step-code'), resend = $('.homacrm-resend');
    var timer = null;
    function say(t, ok) { msg.textContent = t || ''; msg.className = 'homacrm-msg ' + (ok ? 'homacrm-ok' : 'homacrm-bad'); }
    function countdown(sec) {
      clearInterval(timer); resend.disabled = true;
      timer = setInterval(function () {
        sec -= 1;
        if (sec <= 0) { clearInterval(timer); resend.disabled = false; resend.textContent = cfg.i18n.resend; return; }
        resend.textContent = cfg.i18n.wait.replace('%s', sec);
      }, 1000);
      resend.textContent = cfg.i18n.wait.replace('%s', sec);
    }
    function request() {
      say(cfg.i18n.sending, true);
      post('homacrm_otp_request', { mobile: mobile.value }).then(function (r) {
        var d = r.data || {};
        say(d.message, r.success);
        if (r.success) { stepM.hidden = true; stepC.hidden = false; code.focus(); }
        if (d.wait) { countdown(d.wait); }
      });
    }
    function verify() {
      say(cfg.i18n.checking, true);
      post('homacrm_otp_verify', { mobile: mobile.value, code: code.value, redirect: box.getAttribute('data-redirect') || '' }).then(function (r) {
        var d = r.data || {};
        if (r.success && d.redirect) { window.location.href = d.redirect; return; }
        say(d.message, false);
      });
    }
    $('.homacrm-send').addEventListener('click', request);
    resend.addEventListener('click', request);
    $('.homacrm-verify').addEventListener('click', verify);
    mobile.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); request(); } });
    code.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); verify(); } });
    code.addEventListener('input', function () { if (code.value.replace(/\D/g, '').length >= code.maxLength) { verify(); } });
  }
  function boot() { document.querySelectorAll('.homacrm-otp').forEach(init); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
