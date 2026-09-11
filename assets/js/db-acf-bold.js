/**
 * Vetgedrukt op gewone ACF tekstvelden — mét zichtbare opmaak.
 *
 * Een <input> kan geen opmaak tonen; die rendert altijd platte tekst. Daarom
 * verbergen we het echte tekstveld en zetten er een bewerkbaar element voor in
 * de plaats dat de tekst wél vet laat zien. Bij elke wijziging schrijven we de
 * inhoud terug naar het verborgen veld als <strong>…</strong>, zodat ACF en het
 * theme precies krijgen wat ze altijd al kregen.
 *
 * De serialisatie is de vangrail: wat er ook in de editor terechtkomt (plakken,
 * slepen), alleen tekst en <strong> komen in de veldwaarde.
 *
 * Zie classes/bold-toolbar.php voor het aan- en uitzetten per veld.
 */
(function ($) {
  "use strict";

  const WRAPPER = ".db-acf-has-bold";

  /**
   * Inputs waar al een editor aan hangt. Bewust geen data-attribuut: ACF kloont
   * layouts met jQuery, en een attribuut reist mee naar de kloon terwijl de
   * event-handlers dat niet doen. Dan werd de kloon ten onrechte overgeslagen.
   */
  const attached = new WeakSet();

  /** Tags die als opmaak bewaard blijven; de rest wordt platte tekst. */
  const KEEP = { STRONG: true, B: true };

  /** Tekst uit de editor → veilige veldwaarde. Hier moet & wél mee. */
  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }

  /**
   * Veldwaarde → HTML voor de editor.
   *
   * De veldwaarde IS al HTML (het theme echoot 'm), dus & blijft hier met rust:
   * een opgeslagen &amp; hoort in de editor als & te verschijnen. Alleen < en >
   * worden onschadelijk gemaakt, waarna de toegestane tags weer echte tags
   * worden. Een <script> in de waarde blijft dus leesbare tekst.
   *
   * Zou & hier óók ge-escaped worden, dan groeit een waarde met een & bij elke
   * opslag aan: & → &amp; → &amp;amp;.
   */
  function valueToHtml(value) {
    return String(value)
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/&lt;(\/?)(?:strong|b)&gt;/gi, "<$1strong>");
  }

  /**
   * Editor-inhoud → veldwaarde. Loopt de DOM langs en houdt alleen tekst en
   * <strong> over.
   */
  function nodeToValue(node) {
    let out = "";

    Array.prototype.forEach.call(node.childNodes, function (child) {
      if (child.nodeType === 3) {
        out += escapeHtml(child.nodeValue);
        return;
      }
      if (child.nodeType !== 1) {
        return;
      }
      if (child.tagName === "BR") {
        return; // eenregelig veld
      }

      const inner = nodeToValue(child);

      if (KEEP[child.tagName] && inner !== "") {
        out += "<strong>" + inner + "</strong>";
      } else {
        out += inner;
      }
    });

    return out;
  }

  function cleanValue(value) {
    return value
      .replace(/<\/strong><strong>/g, "") // contenteditable knipt stukken op
      .replace(/<strong>\s*<\/strong>/g, "") // lege tags
      .replace(/\u00a0/g, " ") // harde spaties uit contenteditable
      .replace(/\s+$/, "");
  }

  function toValue(editor) {
    return cleanValue(nodeToValue(editor));
  }

  /** Schrijf de editor-inhoud terug naar het echte veld en laat ACF het weten. */
  function sync(editor, input) {
    const next = toValue(editor);
    if (input.value === next) return;

    input.value = next;
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function applyBold(editor) {
    editor.focus();
    try {
      // Voorkomt dat browsers <span style="font-weight:bold"> maken.
      document.execCommand("styleWithCSS", false, false);
    } catch (e) {
      // Niet elke browser accepteert dit commando; niet erg.
    }
    document.execCommand("bold");
  }

  function isBoldNow() {
    try {
      return document.queryCommandState("bold");
    } catch (e) {
      return false;
    }
  }

  function attach($field) {
    const $input = $field.find("> .acf-input input[type='text']").first();
    if (!$input.length) return;

    // Het verborgen sjabloon van flexible content en repeaters overslaan. ACF
    // kloont dat bij "Layout toevoegen"; zat er al een editor in, dan kreeg de
    // nieuwe layout een dode kopie die niet (of niet opgeslagen) bewerkbaar was.
    if ($field.closest(".acf-clone, .acf-flexible-content > .clones").length) return;

    const input = $input[0];
    if (attached.has(input)) return;
    attached.add(input);

    // Bij "Dupliceer layout" komen toolbar en editor van het origineel als losse
    // HTML mee. Weg ermee; hieronder worden ze opnieuw opgebouwd.
    $input.siblings(".db-acf-bold-toolbar, .db-acf-bold-editor").remove();

    const editor = document.createElement("div");
    editor.className = "db-acf-bold-editor";
    editor.setAttribute("role", "textbox");
    editor.setAttribute("aria-multiline", "false");
    editor.innerHTML = valueToHtml(input.value);

    if (input.placeholder) {
      editor.dataset.placeholder = input.placeholder;
    }
    if (input.id) {
      editor.setAttribute("aria-labelledby", input.id + "-label");
    }

    const $toolbar = $('<div class="db-acf-bold-toolbar"></div>');
    const $button = $(
      '<button type="button" class="db-acf-bold-btn" aria-pressed="false" ' +
        'title="Vet maken (⌘/Ctrl + B)"><span aria-hidden="true">B</span>' +
        '<span class="screen-reader-text">Vet maken</span></button>'
    );

    $toolbar.append($button).insertBefore($input);
    $input.after(editor).addClass("db-acf-bold-source");

    // ACF zet het veld na het laden nog aan en uit: een nieuwe layout komt
    // disabled uit het sjabloon en wordt daarna vrijgegeven, en conditionele
    // logica schakelt verborgen velden uit. Editor en knop volgen die stand.
    function isEditable() {
      return !input.readOnly && !input.disabled;
    }

    function updateEditable() {
      const editable = isEditable();
      editor.setAttribute("contenteditable", editable ? "true" : "false");
      $button.prop("disabled", !editable);
    }

    updateEditable();
    new MutationObserver(updateEditable).observe(input, {
      attributes: true,
      attributeFilter: ["disabled", "readonly"],
    });

    function updateState() {
      const active = document.activeElement === editor && isBoldNow();
      $button.toggleClass("is-active", active).attr("aria-pressed", active ? "true" : "false");
    }

    // Knop mag de selectie in de editor niet wegnemen.
    $button.on("mousedown", function (e) {
      e.preventDefault();
    });

    $button.on("click", function (e) {
      e.preventDefault();
      if (!isEditable()) return;
      applyBold(editor);
      sync(editor, input);
      updateState();
    });

    $(editor).on("keydown", function (e) {
      if (e.key === "Enter") {
        e.preventDefault(); // eenregelig veld
        return;
      }
      if ((e.metaKey || e.ctrlKey) && (e.key === "b" || e.key === "B")) {
        e.preventDefault();
        applyBold(editor);
        sync(editor, input);
        updateState();
      }
    });

    // Plakken als platte tekst — anders komt er opmaak van elders mee.
    $(editor).on("paste", function (e) {
      const clipboard = (e.originalEvent || e).clipboardData;
      if (!clipboard) return;
      e.preventDefault();
      const text = clipboard.getData("text/plain").replace(/[\r\n]+/g, " ");
      document.execCommand("insertText", false, text);
    });

    $(editor).on("input", function () {
      sync(editor, input);
    });

    $(editor).on("blur", function () {
      // Normaliseer wat de browser ervan maakte (<b> wordt <strong>, lege tags weg).
      sync(editor, input);
      editor.innerHTML = valueToHtml(input.value);
      updateState();
    });

    $(editor).on("keyup mouseup focus", updateState);
    $(document).on("selectionchange", function () {
      if (document.activeElement === editor) updateState();
    });

    // Klik op het ACF-label zet de cursor in de editor in plaats van in het
    // verborgen veld.
    $field.find("> .acf-label label").on("click", function (e) {
      e.preventDefault();
      editor.focus();
    });

    updateState();
  }

  function attachWithin($el) {
    $el.find(WRAPPER).addBack(WRAPPER).each(function () {
      attach($(this));
    });
  }

  // Voor de tests (node); in de browser bestaat `module` niet.
  if (typeof module !== "undefined" && module.exports) {
    module.exports = { escapeHtml, valueToHtml, nodeToValue, cleanValue, toValue };
  }

  if (!$) return;

  $(function () {
    if (typeof acf === "undefined") return;

    acf.addAction("load_field/type=text", function (field) {
      if (field.$el.is(WRAPPER)) attach(field.$el);
    });

    acf.addAction("append", function ($el) {
      attachWithin($el);
    });

    acf.addAction("after_duplicate", function ($el, $clone) {
      attachWithin($clone);
    });

    attachWithin($(document.body));
  });

})(typeof jQuery !== "undefined" ? jQuery : null);
