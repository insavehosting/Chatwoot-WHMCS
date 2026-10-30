// Stand-in for Chatwoot's /packs/js/sdk.js (v4.19.1 behaviour that the module
// relies on): records every call in window.__cw.calls instead of talking to
// a widget iframe.
(function () {
  function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : undefined;
  }
  function setCookie(name, value) {
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax';
  }
  function removeCookie(name) {
    document.cookie = name + '=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  }
  function record(name, args) {
    window.__cw.calls.push({ name: name, args: JSON.parse(JSON.stringify(args)) });
  }

  window.__cw = window.__cw || { calls: [] };

  window.chatwootSDK = {
    run: function (options) {
      if (window.$chatwoot) return;
      var userCookie = 'cw_user_' + options.websiteToken;
      window.__cw.run = { options: options, settings: window.chatwootSettings };

      window.$chatwoot = {
        baseUrl: options.baseUrl,
        websiteToken: options.websiteToken,
        resetTriggered: false,
        setUser: function (identifier, user) {
          if (typeof identifier !== 'string' && typeof identifier !== 'number') {
            throw new Error('Identifier should be a string or a number');
          }
          if (!user || !(user.avatar_url || user.email || user.name)) {
            throw new Error('User object should have one of the keys [avatar_url, email, name]');
          }
          // The real SDK skips set-user when a hash of these fields is unchanged.
          var hash = JSON.stringify([
            user.avatar_url || '', user.email || '', user.name || '',
            user.identifier_hash || '', user.company_name || '', String(identifier),
          ]);
          if (getCookie(userCookie) === hash) return;
          record('setUser', [identifier, user]);
          setCookie(userCookie, hash);
        },
        setCustomAttributes: function (attributes) {
          if (!attributes || !Object.keys(attributes).length) {
            throw new Error('Custom attributes should have atleast one key');
          }
          record('setCustomAttributes', [attributes]);
        },
        deleteCustomAttribute: function (attribute) {
          if (!attribute) throw new Error('Custom attribute is required');
          record('deleteCustomAttribute', [attribute]);
        },
        setConversationCustomAttributes: function (attributes) {
          if (!attributes || !Object.keys(attributes).length) {
            throw new Error('Custom attributes should have atleast one key');
          }
          record('setConversationCustomAttributes', [attributes]);
        },
        setLabel: function (label) { record('setLabel', [label]); },
        removeLabel: function (label) { record('removeLabel', [label]); },
        setLocale: function (locale) { record('setLocale', [locale]); },
        reset: function () {
          record('reset', []);
          removeCookie(userCookie);
          // The widget reloads and hands out a new conversation token, but
          // chatwoot:ready is not dispatched again after a reset.
          setCookie('cw_conversation', 'token-' + Math.random().toString(36).slice(2));
          window.$chatwoot.resetTriggered = true;
        },
      };

      // The widget's "loaded" event stores its token, then fires chatwoot:ready.
      if (!getCookie('cw_conversation')) {
        setCookie('cw_conversation', 'token-' + Math.random().toString(36).slice(2));
      }
      setTimeout(function () {
        window.dispatchEvent(new CustomEvent('chatwoot:ready'));
        window.__cw.readyFired = true;
      }, 0);
    },
  };
})();
