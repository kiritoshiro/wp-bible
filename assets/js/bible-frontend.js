(function () {
  "use strict";

  if (typeof BibleData === "undefined") return;

  /*
   * Book names are compared with every run of whitespace as one plain space.
   * The database separates the number of a numbered book with a hair space
   * (U+200A), while articles use a normal or non-breaking space ("1 Sam",
   * "2&nbsp;Kar"), so "1 Sam", "1 Kar" and "1 Met" were not found.
   */
  function normName(s) {
    return s.replace(/\s+/g, " ");
  }

  var bookMap = {};
  Object.keys(BibleData.bookMap).forEach(function (name) {
    var key = normName(name);
    if (!bookMap[key]) bookMap[key] = BibleData.bookMap[name];
  });
  var ajaxurl = BibleData.ajaxurl;
  var trigger = BibleData.popupTrigger || "hover";
  var maxWidth = BibleData.popupMaxWidth || 450;

  /* ═══════════════════════════════════════════════
     1. Build regex from book map
     ═══════════════════════════════════════════════ */

  // Sort book names longest first (greedy matching)
  var bookNames = Object.keys(bookMap).sort(function (a, b) {
    return b.length - a.length;
  });

  if (!bookNames.length) return;

  function escRx(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }

  // A space inside a book name matches any whitespace, including non-breaking
  // and hair spaces (\s covers them in JavaScript).
  var bookNamesPattern = bookNames.map(function (name) {
    return escRx(name).replace(/ /g, "\\s+");
  }).join("|");

  // Also build a quick-test regex to check if a string starts with a book name
  // NOTE: \b does NOT work with Lithuanian Unicode chars (ų, ė, š etc.)
  // so we use a lookahead for whitespace, separator, digit, or end of string
  var bookStartRx = new RegExp("^(?:" + bookNamesPattern + ")(?=\\s|[,.:;)\\]]|\\d|$)", "u");

  /*
   * Master reference regex.
   *
   * Groups:
   *   1: Book name
   *   2: Chapter
   *   3: Verse start (after , or :)
   *   4: Number after dash — could be verse_end or chapter_end depending on G5
   *   5: If present → G4 was chapter_end and this is verse_end_ch (cross-chapter)
   *   6: Chapter end (when no verse given, pure chapter range)
   *
   * Patterns matched:
   *   Book 23          → whole chapter           (G2=23)
   *   Book 23,35       → ch:v                    (G2=23, G3=35)
   *   Book 23,35-38    → ch:v1-v2                (G2=23, G3=35, G4=38)
   *   Book 23,35-24,7  → cross-chapter           (G2=23, G3=35, G4=24, G5=7)
   *   Book 23-25       → chapter range           (G2=23, G6=25)
   *   Book 23-25 skyriuose → chapter range + LT  (G2=23, G6=25)
   */
  var refPattern = new RegExp(
    "(" + bookNamesPattern + ")" +       // G1: book name
    "(?:\\s+knygos?|\\s+knyga)?" +       // optional "knygos"/"knyga"
    "\\s+" +                              // required space
    "(\\d{1,3})" +                        // G2: chapter
    "(?:" +
      "\\s*[,.:]\\s*(\\d{1,3})" +        //   G3: verse start (comma, dot, or colon)
      "(?:" +
        "\\s*[-–]\\s*(\\d{1,3})" +       //   G4: number after dash
        "(?:\\s*[,:]\\s*(\\d{1,3}))?" +   //   G5: cross-chapter verse end (comma/colon ONLY, not dot)
      ")?" +
    "|" +
      "\\s*[-–]\\s*(\\d{1,3})" +         //   G6: chapter end (no verse given)
      "(?:\\s+skyri(?:uose|uje|us|ų|ais))?" + // optional Lithuanian word
    ")?",
    "gu"
  );

  /*
   * Continuation regex: after ; within the same text
   * Same structure as reference but without book name.
   *
   * Groups:
   *   1: chapter
   *   2: verse start (after , or :)
   *   3: number after dash
   *   4: cross-chapter verse end
   *   5: chapter end (no verse, chapter range)
   */
  var contRefRx = new RegExp(
    "(\\d{1,3})" +                        // G1: chapter
    "(?:" +
      "\\s*[,.:]\\s*(\\d{1,3})" +        //   G2: verse start (comma, dot, or colon)
      "(?:" +
        "\\s*[-–]\\s*(\\d{1,3})" +       //   G3: number after dash
        "(?:\\s*[,:]\\s*(\\d{1,3}))?" +   //   G4: cross-chapter verse end (comma/colon ONLY)
      ")?" +
    "|" +
      "\\s*[-–]\\s*(\\d{1,3})" +         //   G5: chapter end
    ")?",
    "gu"
  );

  /**
   * Parse a reference match into a structured object
   * Works for both main regex (offset 2-6) and continuation regex (offset 1-5)
   */
  function parseRef(chapter, verseStart, dashNum, crossVerseEnd, chapterEnd) {
    var ref = {
      chapter: parseInt(chapter, 10),
      chapterEnd: null,
      verseStart: null,
      verseEnd: null,
      crossChapterEnd: null,
      crossVerseEnd: null,
      mode: "normal"  // normal | cross_chapter | chapter_range
    };

    if (verseStart) {
      ref.verseStart = parseInt(verseStart, 10);

      if (dashNum) {
        if (crossVerseEnd) {
          // Cross-chapter: ch1,v1 - ch2,v2
          ref.crossChapterEnd = parseInt(dashNum, 10);
          ref.crossVerseEnd = parseInt(crossVerseEnd, 10);
          ref.mode = "cross_chapter";
        } else {
          // Same-chapter verse range: ch,v1-v2
          ref.verseEnd = parseInt(dashNum, 10);
        }
      }
    } else if (chapterEnd) {
      // Chapter range: ch1-ch2
      ref.chapterEnd = parseInt(chapterEnd, 10);
      ref.mode = "chapter_range";
    }
    // else: whole chapter

    return ref;
  }

  /* ═══════════════════════════════════════════════
     2. DOM text walker
     ═══════════════════════════════════════════════ */

  function walkTextNodes(root, callback) {
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: function (node) {
        var p = node.parentElement;
        if (!p) return NodeFilter.FILTER_REJECT;
        var tag = p.tagName;
        if (
          tag === "SCRIPT" || tag === "STYLE" || tag === "TEXTAREA" ||
          tag === "INPUT" || tag === "CODE" || tag === "PRE" ||
          tag === "NOSCRIPT" ||
          p.classList.contains("bible-ref") ||
          p.closest(".bible-popup") ||
          p.closest(".bible-ref") ||
          p.isContentEditable
        ) {
          return NodeFilter.FILTER_REJECT;
        }
        return NodeFilter.FILTER_ACCEPT;
      }
    });

    var nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(callback);
  }

  /* ═══════════════════════════════════════════════
     3. Parse & wrap references in text nodes
     ═══════════════════════════════════════════════ */

  function processTextNode(textNode) {
    var text = textNode.nodeValue;
    if (!text || text.trim().length < 3 || !/\d/.test(text)) return;

    refPattern.lastIndex = 0;

    var match;
    var segments = [];
    var lastIndex = 0;
    var found = false;

    while ((match = refPattern.exec(text)) !== null) {
      var bookName = match[1];
      var bookNum = bookMap[normName(bookName)];
      if (!bookNum) continue;

      var ref = parseRef(match[2], match[3], match[4], match[5], match[6]);

      // Basic validation
      if (ref.chapter < 1 || ref.chapter > 200) continue;

      found = true;

      // Text before match
      if (match.index > lastIndex) {
        segments.push({ type: "text", value: text.substring(lastIndex, match.index) });
      }

      // The matched reference
      segments.push({
        type: "ref",
        value: match[0],
        bookNum: bookNum,
        ref: ref
      });

      lastIndex = match.index + match[0].length;

      // ── Check for dot and semicolon continuations ──
      // Dot (.) = same chapter, different verse(s):  10,29.31  or  17,4-6. 24-41
      // Semicolon (;) = same book, new chapter:verse: ; 18,9-12
      var contPos = lastIndex;
      var lastChapter = ref.chapter; // track current chapter context

      while (contPos < text.length) {
        var remaining = text.substring(contPos);

        // ── Try DOT continuation first (same chapter, new verse) ──
        var dotMatch = remaining.match(/^(\s*\.\s*)(\d{1,3})(?:\s*[-–]\s*(\d{1,3}))?/);
        if (dotMatch) {
          // Make sure this isn't the start of a decimal or sentence
          // (dot continuation must have a digit right after optional space)
          var dotVerseStart = parseInt(dotMatch[2], 10);
          var dotVerseEnd = dotMatch[3] ? parseInt(dotMatch[3], 10) : null;

          if (dotVerseStart >= 1 && dotVerseStart <= 200) {
            var fullDotText = dotMatch[0];

            segments.push({
              type: "ref",
              value: fullDotText,
              bookNum: bookNum,
              ref: {
                chapter: lastChapter,
                chapterEnd: null,
                verseStart: dotVerseStart,
                verseEnd: dotVerseEnd,
                crossChapterEnd: null,
                crossVerseEnd: null,
                mode: "normal"
              },
              isContinuation: true
            });

            contPos += fullDotText.length;
            lastIndex = contPos;
            // lastChapter stays the same
            continue;
          }
        }

        // ── Try SEMICOLON continuation (same book, new chapter) ──
        var semiMatch = remaining.match(/^(\s*;\s*)/);
        if (!semiMatch) break;

        var afterSemi = remaining.substring(semiMatch[0].length);

        // Check if what follows is a NEW book reference → stop continuation.
        var trimmedAfter = afterSemi.replace(/^\s+/, "");

        // Direct book name match: "; Mal 3,1" or "; Izaijo 36,1"
        if (bookStartRx.test(trimmedAfter)) {
          break; // let the main regex handle it as a new book
        }

        // "N BookName" pattern: "; 2 Metraščių 32,1" or "; 1 Sam 13:8"
        // Key insight: valid SAME-BOOK continuations always look like
        //   "18,9-12" or "3:7" or "3,13-4,3"  (number + separator, no space)
        // A "number SPACE letter..." pattern means it's a new book name.
        var numSpaceMatch = trimmedAfter.match(/^(\d+)\s+/);
        if (numSpaceMatch) {
          var afterNumSpace = trimmedAfter.substring(numSpaceMatch[0].length);
          // If after "N " there's anything other than a digit → it's a book name
          if (/^[^\d]/.test(afterNumSpace)) {
            break; // e.g. "2 Metraščių..." → new book reference
          }
        }

        // Try to match a continuation reference
        contRefRx.lastIndex = 0;
        var cMatch = contRefRx.exec(afterSemi);
        if (!cMatch || cMatch.index !== 0) break;

        var cRef = parseRef(cMatch[1], cMatch[2], cMatch[3], cMatch[4], cMatch[5]);
        if (cRef.chapter < 1 || cRef.chapter > 200) break;

        var fullContText = semiMatch[0] + afterSemi.substring(0, cMatch[0].length);

        segments.push({
          type: "ref",
          value: fullContText,
          bookNum: bookNum,
          ref: cRef,
          isContinuation: true
        });

        contPos += fullContText.length;
        lastIndex = contPos;
        lastChapter = cRef.chapter; // update chapter context for any following dot continuations
      }

      refPattern.lastIndex = lastIndex;
    }

    if (!found) return;

    if (lastIndex < text.length) {
      segments.push({ type: "text", value: text.substring(lastIndex) });
    }

    // Build replacement fragment
    var frag = document.createDocumentFragment();
    segments.forEach(function (seg) {
      if (seg.type === "text") {
        frag.appendChild(document.createTextNode(seg.value));
      } else {
        var span = document.createElement("span");
        span.className = "bible-ref";
        span.textContent = seg.value;
        span.setAttribute("data-book", seg.bookNum);
        span.setAttribute("data-chapter", seg.ref.chapter);

        if (seg.ref.mode === "cross_chapter") {
          span.setAttribute("data-mode", "cross_chapter");
          span.setAttribute("data-verse-start", seg.ref.verseStart);
          span.setAttribute("data-chapter-end", seg.ref.crossChapterEnd);
          span.setAttribute("data-verse-end-ch", seg.ref.crossVerseEnd);
        } else if (seg.ref.mode === "chapter_range") {
          span.setAttribute("data-chapter-end", seg.ref.chapterEnd);
        } else {
          if (seg.ref.verseStart !== null)
            span.setAttribute("data-verse-start", seg.ref.verseStart);
          if (seg.ref.verseEnd !== null)
            span.setAttribute("data-verse-end", seg.ref.verseEnd);
        }

        span.setAttribute("tabindex", "0");
        span.setAttribute("role", "button");
        frag.appendChild(span);
      }
    });

    textNode.parentNode.replaceChild(frag, textNode);
  }

  /* ═══════════════════════════════════════════════
     4. Popup management
     ═══════════════════════════════════════════════ */

  var popup = null;
  var popupTimeout = null;
  var currentRef = null;
  var cache = {};

  function createPopup() {
    if (popup) return popup;
    popup = document.createElement("div");
    popup.className = "bible-popup";
    popup.setAttribute("role", "tooltip");
    popup.innerHTML =
      '<div class="bible-popup-header">' +
        '<span class="bible-popup-title"></span>' +
        '<button class="bible-popup-close" aria-label="Uždaryti">&times;</button>' +
      '</div>' +
      '<div class="bible-popup-body"></div>';
    document.body.appendChild(popup);

    popup.querySelector(".bible-popup-close").addEventListener("click", hidePopup);
    popup.addEventListener("mouseenter", function () { clearTimeout(popupTimeout); });
    popup.addEventListener("mouseleave", function () {
      if (trigger === "hover") popupTimeout = setTimeout(hidePopup, 300);
    });

    return popup;
  }

  function showPopup(refEl) {
    createPopup();
    clearTimeout(popupTimeout);

    var bookNum    = refEl.getAttribute("data-book");
    var chapter    = refEl.getAttribute("data-chapter");
    var mode       = refEl.getAttribute("data-mode") || "";
    var chapterEnd = refEl.getAttribute("data-chapter-end") || "";
    var verseStart = refEl.getAttribute("data-verse-start") || "";
    var verseEnd   = refEl.getAttribute("data-verse-end") || "";
    var verseEndCh = refEl.getAttribute("data-verse-end-ch") || "";

    var cacheKey = [bookNum, chapter, chapterEnd, verseStart, verseEnd, verseEndCh, mode].join("_");

    if (cache[cacheKey]) {
      renderPopup(refEl, cache[cacheKey]);
      return;
    }

    // Loading state
    popup.querySelector(".bible-popup-title").textContent = "Kraunama…";
    popup.querySelector(".bible-popup-body").innerHTML =
      '<div class="bible-popup-loading"><span class="bible-spinner"></span></div>';
    positionPopup(refEl);
    popup.classList.add("bible-popup-visible");

    // Build AJAX params
    var params = new URLSearchParams({
      action: "bible_get_verse",
      book_number: bookNum,
      chapter: chapter
    });
    if (mode)       params.set("mode", mode);
    if (chapterEnd) params.set("chapter_end", chapterEnd);
    if (verseStart) params.set("verse_start", verseStart);
    if (verseEnd)   params.set("verse_end", verseEnd);
    if (verseEndCh) params.set("verse_end_ch", verseEndCh);

    fetch(ajaxurl + "?" + params.toString())
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          cache[cacheKey] = data.data;
          renderPopup(refEl, data.data);
        } else {
          popup.querySelector(".bible-popup-body").textContent =
            (data.data && data.data.message ? data.data.message : "Eilutė nerasta");
        }
      })
      .catch(function () {
        popup.querySelector(".bible-popup-body").innerHTML =
          '<p class="bible-popup-error">Klaida kreipiantis į serverį</p>';
      });
  }

  function renderPopup(refEl, data) {
    popup.querySelector(".bible-popup-title").textContent = data.title || "";
    popup.querySelector(".bible-popup-body").innerHTML = data.html || "";
    positionPopup(refEl);
    popup.classList.add("bible-popup-visible");
  }

  function positionPopup(refEl) {
    var rect = refEl.getBoundingClientRect();
    var scrollY = window.pageYOffset || document.documentElement.scrollTop;
    var scrollX = window.pageXOffset || document.documentElement.scrollLeft;
    var vpW = window.innerWidth;
    var vpH = window.innerHeight;

    popup.style.maxWidth = maxWidth + "px";
    popup.style.left = "0px";
    popup.style.top = "0px";
    popup.style.display = "block";

    var popRect = popup.getBoundingClientRect();

    var top = rect.bottom + scrollY + 8;
    var left = rect.left + scrollX;

    if (left + popRect.width > vpW + scrollX - 10) {
      left = vpW + scrollX - popRect.width - 10;
    }
    if (left < scrollX + 10) left = scrollX + 10;

    if (rect.bottom + popRect.height + 20 > vpH) {
      top = rect.top + scrollY - popRect.height - 8;
      if (top < scrollY + 10) top = rect.bottom + scrollY + 8;
    }

    popup.style.left = left + "px";
    popup.style.top = top + "px";
  }

  function hidePopup() {
    if (popup) popup.classList.remove("bible-popup-visible");
    currentRef = null;
  }

  function closestFromEvent(e, selector) {
    var target = e && e.target;

    // Some browser/plugin events can target document/window/text nodes.
    // closest() exists only on Element nodes, so normalize safely.
    if (!target) return null;
    if (target.nodeType === 3) target = target.parentElement; // Text node
    if (!target || target.nodeType !== 1 || typeof target.closest !== "function") {
      return null;
    }

    return target.closest(selector);
  }

  /* ═══════════════════════════════════════════════
     5. Event delegation
     ═══════════════════════════════════════════════ */

  function attachEvents() {
    if (trigger === "hover") {
      document.addEventListener("mouseenter", function (e) {
        var ref = closestFromEvent(e, ".bible-ref");
        if (!ref) return;
        currentRef = ref;
        clearTimeout(popupTimeout);
        popupTimeout = setTimeout(function () { showPopup(ref); }, 200);
      }, true);

      document.addEventListener("mouseleave", function (e) {
        var ref = closestFromEvent(e, ".bible-ref");
        if (!ref) return;
        popupTimeout = setTimeout(hidePopup, 400);
      }, true);
    } else {
      document.addEventListener("click", function (e) {
        var ref = closestFromEvent(e, ".bible-ref");
        if (ref) {
          e.preventDefault();
          if (currentRef === ref && popup && popup.classList.contains("bible-popup-visible")) {
            hidePopup();
          } else {
            currentRef = ref;
            showPopup(ref);
          }
        } else if (popup && !closestFromEvent(e, ".bible-popup")) {
          hidePopup();
        }
      });
    }

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") hidePopup();
    });

    var repositionTimer;
    function onReposition() {
      clearTimeout(repositionTimer);
      repositionTimer = setTimeout(function () {
        if (currentRef && popup && popup.classList.contains("bible-popup-visible")) {
          positionPopup(currentRef);
        }
      }, 50);
    }
    window.addEventListener("scroll", onReposition, { passive: true });
    window.addEventListener("resize", onReposition, { passive: true });
  }

  /* ═══════════════════════════════════════════════
     6. Initialize
     ═══════════════════════════════════════════════ */

  function init() {
    // Process the whole page
    var targets = document.querySelectorAll("article, .entry-content, #content, main");
    if (!targets.length) targets = [document.body];

    targets.forEach(function (t) {
      walkTextNodes(t, processTextNode);
    });

    attachEvents();

    // Watch for dynamically added content
    if (typeof MutationObserver !== "undefined") {
      var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          m.addedNodes.forEach(function (node) {
            if (node.nodeType === Node.ELEMENT_NODE) {
              walkTextNodes(node, processTextNode);
            }
          });
        });
      });
      observer.observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
