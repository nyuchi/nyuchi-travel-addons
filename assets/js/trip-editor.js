/**
 * Trip editor meta box: tabs and repeaters.
 *
 * No jQuery. The meta box is plain markup rendered by PHP, and everything here
 * is either showing a panel or keeping repeater indexes contiguous, neither of
 * which needs a library.
 *
 * Indexes matter more than they look: the PHP sanitisers read
 * wta[legs][0][title] and friends positionally, so a gap left by a removed row
 * would push the rest of the array out of shape. Every add and every remove is
 * followed by a full renumber.
 */
(function () {
    'use strict';

    var ROOT = '.wta-editor';

    /* --------------------------------------------------------------- helpers */

    function each(list, fn) {
        Array.prototype.forEach.call(list, fn);
    }

    function filter(list, fn) {
        return Array.prototype.filter.call(list, fn);
    }

    /** Rows belonging directly to this repeater, not to a nested one. */
    function rowsOf(repeater) {
        var holder = repeater.querySelector('[data-wta-rows]');

        if (!holder) {
            return [];
        }

        return filter(holder.children, function (node) {
            return node.hasAttribute && node.hasAttribute('data-wta-row');
        });
    }

    /** Controls that belong to this row rather than to a row nested inside it. */
    function fieldsOf(row) {
        return filter(row.querySelectorAll('[data-wta-name]'), function (el) {
            return el.closest('[data-wta-row]') === row;
        });
    }

    /** Repeaters sitting directly in this row. */
    function nestedIn(row) {
        return filter(row.querySelectorAll('[data-wta-repeater]'), function (el) {
            return el.closest('[data-wta-row]') === row;
        });
    }

    /* ------------------------------------------------------------ renumbering */

    /**
     * Rewrite every name under one repeater so the row indexes read 0, 1, 2...
     *
     * Recurses into nested repeaters, whose base path contains the parent row's
     * index and therefore changes whenever the parent list does.
     */
    function renumber(repeater, base) {
        rowsOf(repeater).forEach(function (row, index) {
            var prefix = base + '[' + index + ']';

            fieldsOf(row).forEach(function (el) {
                el.name = prefix + el.getAttribute('data-wta-name');
            });

            nestedIn(row).forEach(function (nested) {
                renumber(nested, prefix + (nested.getAttribute('data-wta-sub') || ''));
            });
        });
    }

    /** Renumber from the outermost repeaters down. */
    function renumberAll(root) {
        var repeaters = filter(root.querySelectorAll('[data-wta-repeater]'), function (el) {
            return !el.closest('[data-wta-row]');
        });

        repeaters.forEach(function (repeater) {
            renumber(repeater, repeater.getAttribute('data-wta-base') || '');
        });
    }

    /* ------------------------------------------------------------------ tabs */

    function initTabs(root) {
        var tabs = root.querySelectorAll('.wta-tab');

        each(tabs, function (tab) {
            tab.addEventListener('click', function () {
                each(tabs, function (other) {
                    var panel = root.querySelector('#' + other.getAttribute('aria-controls'));
                    var on = other === tab;

                    other.setAttribute('aria-selected', on ? 'true' : 'false');
                    other.classList.toggle('is-active', on);

                    if (panel) {
                        panel.classList.toggle('is-active', on);
                        panel.hidden = !on;
                    }
                });
            });
        });
    }

    /* -------------------------------------------------------------- repeaters */

    /** Whether the row holds anything the author would be sorry to lose. */
    function rowHasContent(row) {
        return fieldsOf(row).some(function (el) {
            // A select always reports a value, so only a moved-off-default
            // selection counts as content.
            if (el.tagName === 'SELECT') {
                return el.selectedIndex > 0;
            }

            return String(el.value).trim() !== '';
        }) || nestedIn(row).some(function (nested) {
            return rowsOf(nested).some(rowHasContent);
        });
    }

    function initRepeaters(root) {
        root.addEventListener('click', function (event) {
            var target = event.target;

            if (!target || !target.closest) {
                return;
            }

            var add = target.closest('[data-wta-add]');

            if (add && root.contains(add)) {
                var repeater = add.closest('[data-wta-repeater]');
                var template = repeater.querySelector('[data-wta-template]');
                var holder = repeater.querySelector('[data-wta-rows]');

                if (!template || !holder) {
                    return;
                }

                holder.appendChild(template.content.cloneNode(true));
                renumberAll(root);

                return;
            }

            var remove = target.closest('[data-wta-remove]');

            if (remove && root.contains(remove)) {
                var row = remove.closest('[data-wta-row]');

                if (!row) {
                    return;
                }

                if (rowHasContent(row) && !window.confirm('Remove this row? Its contents will be lost.')) {
                    return;
                }

                row.parentNode.removeChild(row);
                renumberAll(root);
            }
        });
    }

    /* ------------------------------------------------------------------ boot */

    function init() {
        var root = document.querySelector(ROOT);

        if (!root) {
            return;
        }

        initTabs(root);
        initRepeaters(root);

        // Stored rows are already numbered correctly by PHP; this only guards
        // against markup that was rendered from a partly saved structure.
        renumberAll(root);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
