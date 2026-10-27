/**
 * FAQ repeater on the term edit screens.
 *
 * The rows are ordinary inputs, so the form saves without this script. It only
 * adds what a form cannot do alone: more rows, removing a row and reordering.
 * Order on save is the order of the rows on screen, so nothing is renumbered.
 */
(function () {
  "use strict";

  function init(editor) {
    var list = editor.querySelector(".wta-faq-rows");
    var template = editor.querySelector("template.wta-faq-template");
    var addWrap = editor.querySelector(".wta-faq-add-wrap");
    var next =
      parseInt(editor.getAttribute("data-next"), 10) || list.children.length;
    var max = parseInt(editor.getAttribute("data-max"), 10) || 50;

    if (!list || !template) {
      return;
    }

    // The buttons only make sense with a script behind them.
    if (addWrap) {
      addWrap.hidden = false;
    }
    Array.prototype.forEach.call(
      editor.querySelectorAll(".wta-faq-actions"),
      function (el) {
        el.hidden = false;
      },
    );

    function rows() {
      return list.querySelectorAll(".wta-faq-row");
    }

    function syncAdd() {
      var button = editor.querySelector(".wta-faq-add");

      if (button) {
        button.disabled = rows().length >= max;
      }
    }

    function addRow() {
      if (rows().length >= max) {
        return null;
      }

      var holder = document.createElement("ol");
      holder.innerHTML = template.innerHTML
        .split("__INDEX__")
        .join(String(next));
      next += 1;

      var row = holder.querySelector(".wta-faq-row");
      list.appendChild(row);
      row.querySelector(".wta-faq-actions").hidden = false;
      syncAdd();

      return row;
    }

    editor.addEventListener("click", function (event) {
      var target = event.target;

      if (!(target instanceof Element)) {
        return;
      }

      var row = target.closest(".wta-faq-row");

      if (target.closest(".wta-faq-add")) {
        var created = addRow();

        if (created) {
          created.querySelector("input").focus();
        }

        return;
      }

      if (!row) {
        return;
      }

      if (target.closest(".wta-faq-remove")) {
        if (rows().length === 1) {
          // Keep one row to type into rather than leaving the field empty.
          Array.prototype.forEach.call(
            row.querySelectorAll("input, textarea"),
            function (el) {
              el.value = "";
            },
          );
        } else {
          row.remove();
        }
        syncAdd();
      } else if (target.closest(".wta-faq-up") && row.previousElementSibling) {
        list.insertBefore(row, row.previousElementSibling);
        target.focus();
      } else if (target.closest(".wta-faq-down") && row.nextElementSibling) {
        list.insertBefore(row.nextElementSibling, row);
        target.focus();
      }
    });

    syncAdd();
  }

  document.addEventListener("DOMContentLoaded", function () {
    Array.prototype.forEach.call(
      document.querySelectorAll(".wta-faq-editor"),
      init,
    );
  });
})();
