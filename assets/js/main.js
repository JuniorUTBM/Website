/* Junior UTBM — améliorations progressives.
   Le site fonctionne sans ce fichier : il ajoute seulement du confort. */
(function () {
  "use strict";

  var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

  /* 1. Défilement de l'équipe : boutons précédent / suivant ---------------- */
  document.querySelectorAll("[data-scroller]").forEach(function (root) {
    var viewport = root.querySelector("[data-scroller-viewport]");
    var prev = root.querySelector("[data-scroller-prev]");
    var next = root.querySelector("[data-scroller-next]");
    if (!viewport || !prev || !next) return;
    prev.parentElement.hidden = false;

    function step(direction) {
      viewport.scrollBy({
        left: direction * viewport.clientWidth * 0.8,
        behavior: reduceMotion.matches ? "auto" : "smooth"
      });
    }
    // Désactive un bouton en bout de liste (tolérance de 2 px pour l'arrondi)
    function update() {
      var max = viewport.scrollWidth - viewport.clientWidth;
      prev.disabled = viewport.scrollLeft <= 2;
      next.disabled = viewport.scrollLeft >= max - 2;
    }
    prev.addEventListener("click", function () { step(-1); });
    next.addEventListener("click", function () { step(1); });
    viewport.addEventListener("scroll", update, { passive: true });
    window.addEventListener("resize", update);
    update();
  });

  /* 2. Rôle qui défile (page Étudiants) ------------------------------------
     Le texte animé est aria-hidden : les lecteurs d'écran lisent la liste.
     Un bouton permet de mettre en pause (critère WCAG 2.2.2). */
  var rotator = document.querySelector("[data-rotator]");
  if (rotator) {
    var word = rotator.querySelector("[data-rotator-word]");
    var items = rotator.querySelectorAll("[data-rotator-list] li");
    var index = 0;
    var timer = null;

    function show(i) {
      items.forEach(function (li, j) { li.classList.toggle("is-current", j === i); });
      word.textContent = items[i].textContent;
      if (!reduceMotion.matches) {
        word.classList.remove("is-entering");
        void word.offsetWidth; // relance l'animation CSS
        word.classList.add("is-entering");
      }
    }

    var toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "rotator-toggle";
    var ICON_PAUSE = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 2h3v12H4zM9 2h3v12H9z" fill="currentColor"/></svg>';
    var ICON_PLAY = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 2l10 6-10 6z" fill="currentColor"/></svg>';

    function play() {
      timer = window.setInterval(function () {
        index = (index + 1) % items.length;
        show(index);
      }, 2500);
      toggle.innerHTML = ICON_PAUSE + "Mettre en pause l’animation";
      toggle.setAttribute("aria-pressed", "false");
    }
    function pause() {
      window.clearInterval(timer);
      timer = null;
      toggle.innerHTML = ICON_PLAY + "Relancer l’animation";
      toggle.setAttribute("aria-pressed", "true");
    }
    toggle.addEventListener("click", function () { timer ? pause() : play(); });

    show(0);
    // Mouvement réduit : texte fixe, pas d'animation automatique
    if (!reduceMotion.matches && items.length > 1) {
      // Placé après le paragraphe aria-hidden pour rester accessible
      word.parentElement.insertAdjacentElement("afterend", toggle);
      play();
    }
  }

  /* 3. Formulaire de contact ------------------------------------------------ */
  var status = document.querySelector("[data-form-status]");
  if (status) {
    var params = new URLSearchParams(window.location.search);
    if (params.has("envoye")) {
      status.className = "form-status form-status--success";
      status.textContent = "Merci ! Votre message a bien été envoyé.";
    } else if (params.has("erreur")) {
      status.className = "form-status form-status--error";
      status.innerHTML = "L’envoi a échoué. Écrivez-nous directement à " +
        '<a href="mailto:contact@juniorutbm.fr">contact@juniorutbm.fr</a>.';
    }
  }

  var form = document.querySelector("[data-contact-form]");
  if (form) {
    // Relie chaque champ invalide à son message d'erreur (lu par les lecteurs d'écran)
    function errorFor(field) {
      return document.getElementById("erreur-" + field.name);
    }
    function setInvalid(field, invalid) {
      var group = field.type === "radio" ? form.querySelectorAll('[name="' + field.name + '"]') : [field];
      var err = errorFor(field);
      group.forEach(function (el) {
        var ids = (el.getAttribute("aria-describedby") || "").split(" ").filter(Boolean);
        ids = ids.filter(function (id) { return !err || id !== err.id; });
        if (invalid && err) ids.push(err.id);
        if (ids.length) el.setAttribute("aria-describedby", ids.join(" "));
        else el.removeAttribute("aria-describedby");
        if (invalid) el.setAttribute("aria-invalid", "true");
        else el.removeAttribute("aria-invalid");
      });
    }
    form.addEventListener("invalid", function (e) { setInvalid(e.target, true); }, true);
    form.addEventListener("input", function (e) {
      if (e.target.getAttribute("aria-invalid") && e.target.checkValidity()) setInvalid(e.target, false);
    });
    form.addEventListener("change", function (e) {
      if (e.target.checkValidity()) setInvalid(e.target, false);
    });
    // Évite les doubles envois
    form.addEventListener("submit", function () {
      var btn = form.querySelector('[type="submit"]');
      btn.disabled = true;
      btn.textContent = "Envoi en cours…";
    });
  }
})();
