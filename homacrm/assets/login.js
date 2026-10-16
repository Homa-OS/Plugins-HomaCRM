/* HomaCRM — ورود و ثبت‌نام با پیامک. بی‌وابستگی (بدون jQuery). */
(function () {
  'use strict';
  var cfg = window.HomaCRMLogin || {};
  var FA = '۰۱۲۳۴۵۶۷۸۹', AR = '٠١٢٣٤٥٦٧٨٩';
  /** رقم‌های فارسی و عربی ⇒ لاتین؛ هر چیزِ غیرِ رقم (و + ابتدای شماره) حذف. */
  function digits(v, keepPlus) {
    v = String(v || '').replace(/[۰-۹]/g, function (c) { return FA.indexOf(c); }).replace(/[٠-٩]/g, function (c) { return AR.indexOf(c); });
    return keepPlus ? v.replace(/(?!^\+)[^\d]/g, '') : v.replace(/\D/g, '');
  }
  function normMobile(v) {
    var d = digits(v);
    if (d.indexOf('0098') === 0) d = d.slice(4); else if (d.indexOf('98') === 0 && d.length === 12) d = d.slice(2);
    if (d.length === 10 && d[0] === '9') d = '0' + d;
    return d;
  }
  function fa(n) { return String(n).replace(/\d/g, function (d) { return FA[d]; }); }
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
    var stepM = $('.homacrm-step-mobile'), stepC = $('.homacrm-step-code'), resend = $('.homacrm-resend'), send = $('.homacrm-send'), edit = $('.homacrm-edit');
    var timer = null, busy = false, base = '';
    function say(t, ok) { base = t || ''; msg.textContent = base; msg.className = 'homacrm-msg ' + (ok ? 'homacrm-ok' : 'homacrm-bad'); }
    function countdown(sec, withMsg) {
      clearInterval(timer);
      var btns = [send, resend];
      function paint() {
        btns.forEach(function (b) { b.disabled = true; b.textContent = cfg.i18n.wait.replace('%s', fa(sec)); });
        if (withMsg) { msg.textContent = (base ? base + ' ' : '') + cfg.i18n.waitMsg.replace('%s', fa(sec)); }
      }
      paint();
      timer = setInterval(function () {
        sec -= 1;
        if (sec <= 0) {
          clearInterval(timer);
          send.disabled = false; send.textContent = cfg.i18n.send;
          resend.disabled = false; resend.textContent = cfg.i18n.resend;
          if (withMsg) { msg.textContent = ''; }
          return;
        }
        paint();
      }, 1000);
    }
    function showCode(m) { stepM.hidden = true; stepC.hidden = false; say(cfg.i18n.sentTo.replace('%s', fa(m)), true); code.value = ''; code.focus(); }
    function request() {
      if (busy) return;
      var m = normMobile(mobile.value);
      mobile.value = m;
      if (!/^09\d{9}$/.test(m)) { say(cfg.i18n.badMobile, false); mobile.focus(); return; }
      busy = true; say(cfg.i18n.sending, true);
      post('homacrm_otp_request', { mobile: m }).then(function (r) {
        busy = false;
        var d = r.data || {};
        if (r.success || d.sent) { showCode(m); if (d.sent) { base = d.message; } }
        else { say(d.message, false); }
        if (d.wait) { countdown(d.wait, !r.success && !d.sent); }
      });
    }
    function verify() {
      var c = digits(code.value);
      code.value = c;
      if (busy || c.length < 4) return;
      busy = true; say(cfg.i18n.checking, true);
      post('homacrm_otp_verify', { mobile: normMobile(mobile.value), code: c, redirect: box.getAttribute('data-redirect') || '' }).then(function (r) {
        busy = false;
        var d = r.data || {};
        if (r.success) { if (d.redirect) { window.location.href = d.redirect; } else { window.location.reload(); } return; }
        say(d.message, false); code.select();
      });
    }
    mobile.addEventListener('input', function () { var v = digits(mobile.value, true); if (v !== mobile.value) mobile.value = v; });
    code.addEventListener('input', function () {
      var v = digits(code.value);
      if (v !== code.value) code.value = v;
      if (v.length >= (code.maxLength > 0 ? code.maxLength : 5)) verify();
    });
    send.addEventListener('click', request);
    resend.addEventListener('click', request);
    if (edit) edit.addEventListener('click', function () { stepC.hidden = true; stepM.hidden = false; say('', true); mobile.focus(); });
    $('.homacrm-verify').addEventListener('click', verify);
    mobile.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); request(); } });
    code.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); verify(); } });
  }
  function boot() { document.querySelectorAll('.homacrm-otp').forEach(init); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
