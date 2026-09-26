/* ==========================================================
   InternBoot — Landing Page (M2)
   Pure JavaScript + Bootstrap 5
   
   EDIT ONLY THE CONFIG BELOW when the client confirms the
   pending numbers (fee, batch size, duration, level ranges).
   Nothing is hardcoded anywhere in index.html.
   ========================================================== */

const IB_CONFIG = {
  /* --- money & batch --- */
  fee: "₹2999",
  feeShort: "₹2999",
  gstNote: "+ 18% GST applicable",
  batchSize: "100",
  batchNumber: "",

  /* --- exam --- */
  duration: "60 minutes",
  questions: "100 questions",

  /* --- result --- */
  resultTiming: "within a few minutes of submission",
  retake: "No, you can only take the test once per drive.",
  placementLevels: "1 and 2",

  /* --- levels: name, score range, color, description, key characteristic, perks, progress --- */
  levels: [
    {
      level: 1,
      name: "Top / Excellent",
      range: "85 – 100%",
      color: "#10b981",
      glowColor: "rgba(16, 185, 129, 0.25)",
      tier: "Apex Mastery",
      tierIcon: "bi-trophy-fill",
      desc: "Eligible for premium placement tracks. You’re shortlisted first across our hiring partner network.",
      characteristic: "Priority Interview Callbacks • Top 5% Talent Pool",
      percentile: "Top 5% of Fresher Candidates",
      scoreProgress: 100,
      perks: ["⚡ 1st callbacks guaranteed", "🤝 650+ hiring partners"]
    },
    {
      level: 2,
      name: "Intermediate",
      range: "70 – 84%",
      color: "#3b82f6",
      glowColor: "rgba(59, 130, 246, 0.25)",
      tier: "Recruiter Priority",
      tierIcon: "bi-lightning-charge-fill",
      desc: "Solid, job-ready fundamentals. Regularly surfaced to recruiters for mid-track roles.",
      characteristic: "Priority Partner Dispatch • Accelerated Screening",
      percentile: "Top 20% Talent Pool",
      scoreProgress: 84,
      perks: ["💼 Weekly recruiter digest", "🤝 300+ hiring partners"]
    },
    {
      level: 3,
      name: "Basic / Employable",
      range: "55 – 69%",
      color: "#06b6d4",
      glowColor: "rgba(6, 182, 212, 0.25)",
      tier: "Ready to Hire",
      tierIcon: "bi-patch-check-fill",
      desc: "Solid grasp of fundamentals and core application principles. Ready for entry-level internship and developer roles.",
      characteristic: "Verified Internship Roster • Direct Company Review",
      percentile: "Top 45% Benchmark Roster",
      scoreProgress: 69,
      perks: ["✓ Verified internship roster", "🤝 150+ hiring partners"]
    },
    {
      level: 4,
      name: "Basic Knowledge",
      range: "40 – 54%",
      color: "#f59e0b",
      glowColor: "rgba(245, 158, 11, 0.25)",
      tier: "Core Aptitude",
      tierIcon: "bi-journal-code",
      desc: "Working knowledge of core concepts, with room to build execution speed and specialized project depth.",
      characteristic: "Foundational Talent Pool • Practice Module Access",
      percentile: "Foundation Candidate Pool",
      scoreProgress: 54,
      perks: ["📖 Diagnostic skill report", "🎯 Practice modules access"]
    },
    {
      level: 5,
      name: "Needs Training",
      range: "0 – 39%",
      color: "#f43f5e",
      glowColor: "rgba(244, 63, 94, 0.25)",
      tier: "Training Track",
      tierIcon: "bi-arrow-clockwise",
      desc: "Foundation level skills requiring structured training and conceptual consolidation before placement dispatch.",
      characteristic: "Guided Learning Path • Retake Token Eligibility",
      percentile: "Development Candidate Pool",
      scoreProgress: 39,
      perks: ["🔄 Retake token included", "📚 Structured curriculum"]
    }
  ]
};

document.addEventListener("DOMContentLoaded", () => {

  /* ---------- 1. Push config values into the page ---------- */
  document.querySelectorAll("[data-ib]").forEach(el => {
    const key = el.getAttribute("data-ib");
    if (IB_CONFIG[key]) el.textContent = IB_CONFIG[key];
  });

  /* ---------- 2. Hero ladder with enhanced visuals & icons ---------- */
  const ladder = document.getElementById("heroLadder");
  if (ladder) {
    ladder.innerHTML = IB_CONFIG.levels.map(l => `
      <div class="rung" data-level="${l.level}" data-tooltip="Score ${l.range}">
        <span class="rung-no">${l.level}</span>
        <span class="rung-name">
          ${l.name}
    
        </span>
        <span class="rung-score">${l.range}</span>
      </div>`).join("");
  }

  /* ---------- 2b. Ladder card 3D tilt — pressed side down, opposite side up ---------- */
  const ladderCard = document.querySelector(".ladder-card");
  if (ladderCard) {
    const MAX_TILT = 16;

    const applyTilt = (clientX, clientY) => {
      const rect = ladderCard.getBoundingClientRect();
      const x = Math.min(Math.max(clientX - rect.left, 0), rect.width);
      const y = Math.min(Math.max(clientY - rect.top, 0), rect.height);
      const rotateY = -((x / rect.width) - 0.5) * 2 * MAX_TILT;
      const rotateX = -((y / rect.height) - 0.5) * 2 * MAX_TILT;

      ladderCard.style.setProperty("--tilt-x", `${rotateX.toFixed(2)}deg`);
      ladderCard.style.setProperty("--tilt-y", `${rotateY.toFixed(2)}deg`);
      ladderCard.style.setProperty("--lift", "-10px");
      ladderCard.classList.add("is-tilting");
    };

    const resetTilt = () => {
      ladderCard.classList.remove("is-tilting");
      ladderCard.style.setProperty("--tilt-x", "0deg");
      ladderCard.style.setProperty("--tilt-y", "0deg");
      ladderCard.style.setProperty("--lift", "0px");
    };

    ladderCard.addEventListener("mousemove", (e) => applyTilt(e.clientX, e.clientY));
    ladderCard.addEventListener("mouseleave", resetTilt);

    ladderCard.addEventListener("touchstart", (e) => {
      if (e.touches[0]) applyTilt(e.touches[0].clientX, e.touches[0].clientY);
    }, { passive: true });

    ladderCard.addEventListener("touchmove", (e) => {
      if (e.touches[0]) applyTilt(e.touches[0].clientX, e.touches[0].clientY);
    }, { passive: true });

    ladderCard.addEventListener("touchend", resetTilt);
    ladderCard.addEventListener("touchcancel", resetTilt);
  }

  /* ---------- 2c. How It Works — scroll reveal + active step highlight ---------- */
  const hiwSteps = document.querySelectorAll(".hiw-flow-step");
  if (hiwSteps.length) {
    hiwSteps.forEach((step, index) => {
      step.style.setProperty("--hiw-delay", `${index * 0.08}s`);
    });

    if ("IntersectionObserver" in window) {
      const revealObserver = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              entry.target.classList.add("is-revealed");
              revealObserver.unobserve(entry.target);
            }
          });
        },
        { threshold: 0.2, rootMargin: "0px 0px -40px 0px" }
      );
      hiwSteps.forEach((step) => revealObserver.observe(step));

      const activeObserver = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              hiwSteps.forEach((step) => step.classList.remove("is-active"));
              entry.target.classList.add("is-active");
            }
          });
        },
        { threshold: 0.55, rootMargin: "-25% 0px -25% 0px" }
      );
      hiwSteps.forEach((step) => activeObserver.observe(step));
    } else {
      hiwSteps.forEach((step) => {
        step.classList.add("is-revealed");
        step.classList.add("is-active");
      });
    }
  }

  /* ---------- 3. Level cards: Scroll-Driven Scaling Stack Layout ---------- */
  const grid = document.getElementById("levelGrid");
  if (grid) {
    grid.className = "levels-stack-container";
    grid.innerHTML = IB_CONFIG.levels.map((l, idx) => `
      <div class="level-stack-item" data-index="${idx}" style="--stack-idx: ${idx};">
        <div class="level-card" data-level-card="${l.level}" style="--level-color: ${l.color}; --level-glow: ${l.glowColor};">
          <!-- Top Row: Badge + Action Arrow -->
          <div class="level-card-header">
            <div class="level-pill-badge">
              LEVEL 0${l.level} &nbsp;•&nbsp; ${l.tier.toUpperCase()}
            </div>
            <div class="level-card-arrow" aria-label="Level details">
              <i class="bi bi-arrow-up-right"></i>
            </div>
          </div>

          <!-- Level Title -->
          <h3 class="level-card-title">${l.name}</h3>

          <!-- Level Description -->
          <p class="level-card-desc">${l.desc}</p>

          <!-- Visual Score Benchmark Box -->
          <div class="level-benchmark-box">
            <div class="level-benchmark-header">
              <span class="level-benchmark-label">SCORE BENCHMARK</span>
              <span class="level-benchmark-val">${l.range}</span>
            </div>
            <div class="level-benchmark-track">
              <div class="level-benchmark-fill" style="width: ${l.scoreProgress}%;"></div>
            </div>
          </div>

          <!-- Candidate Perks & Highlights Chips -->
          <div class="level-perks-row">
            ${l.perks.map(p => `<span class="level-perk-chip">${p}</span>`).join('')}
          </div>
        </div>
      </div>`).join("");

    /* ---------- Animated Benchmark Fill on Scroll Into View ---------- */
    const meterFills = grid.querySelectorAll(".level-benchmark-fill");
    meterFills.forEach(fill => {
      const targetWidth = fill.style.width;
      fill.style.width = "0%";
      fill.dataset.targetWidth = targetWidth;
    });

    if ("IntersectionObserver" in window && meterFills.length) {
      const meterObserver = new IntersectionObserver((entries, obs) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            const fill = entry.target;
            setTimeout(() => {
              fill.style.width = fill.dataset.targetWidth;
            }, 200);
            obs.unobserve(fill);
          }
        });
      }, { threshold: 0.2 });
      meterFills.forEach(f => meterObserver.observe(f));
    }

    /* Fallback scaling for browsers without native CSS scroll-driven animation-timeline */
    const supportsCssTimeline = window.CSS && CSS.supports && CSS.supports("animation-timeline", "view()");
    if (!supportsCssTimeline) {
      let ticking = false;
      const stackItems = grid.querySelectorAll(".level-stack-item");
      const handleStackScroll = () => {
        if (!ticking) {
          window.requestAnimationFrame(() => {
            stackItems.forEach((item, i) => {
              const card = item.querySelector(".level-card");
              if (!card) return;
              const rect = item.getBoundingClientRect();
              const stickyThreshold = 95 + i * 22;
              if (rect.top <= stickyThreshold + 2) {
                const diff = Math.min(1, Math.max(0, (stickyThreshold - rect.top) / 350));
                const scale = 1 - diff * 0.04;
                card.style.transform = `scale(${scale.toFixed(4)})`;
                card.style.filter = `brightness(${(1 - diff * 0.18).toFixed(2)})`;
              } else {
                card.style.transform = "";
                card.style.filter = "";
              }
            });
            ticking = false;
          });
          ticking = true;
        }
      };
      window.addEventListener("scroll", handleStackScroll, { passive: true });
    }
  }

  /* ---------- 4. Interactive Score & Level Estimator ---------- */
  const scoreInput = document.getElementById("scoreRangeInput");
  const scoreDisplay = document.getElementById("scoreValDisplay");
  const levelBadgeDisplay = document.getElementById("levelBadgeDisplay");
  const levelDescDisplay = document.getElementById("levelDescDisplay");

  const updateEstimator = (score) => {
    if (!scoreDisplay) return;
    scoreDisplay.textContent = score + "%";

    let matched = IB_CONFIG.levels[4];
    if (score >= 85) {
      matched = IB_CONFIG.levels[0];
    } else if (score >= 70) {
      matched = IB_CONFIG.levels[1];
    } else if (score >= 55) {
      matched = IB_CONFIG.levels[2];
    } else if (score >= 40) {
      matched = IB_CONFIG.levels[3];
    } else {
      matched = IB_CONFIG.levels[4];
    }

    if (levelBadgeDisplay) {
      const isPriority = matched.level <= 2;
      levelBadgeDisplay.innerHTML = `
        <span class="badge ${isPriority ? 'bg-warning text-dark' : 'bg-primary'} px-3 py-2 rounded-pill">
          Level ${matched.level}: ${matched.name} ${isPriority ? '★ Priority' : ''}
        </span>`;
    }

    if (levelDescDisplay) {
      levelDescDisplay.textContent = matched.desc;
    }

    // Highlight matching level card slightly if visible
    document.querySelectorAll("[data-level-card]").forEach(card => {
      const lvl = parseInt(card.getAttribute("data-level-card"), 10);
      if (lvl === matched.level) {
        card.style.borderColor = "var(--brand)";
        card.style.boxShadow = "0 14px 30px -8px rgba(22, 82, 214, 0.28)";
      } else {
        card.style.borderColor = "";
        card.style.boxShadow = "";
      }
    });
  };

  if (scoreInput) {
    const paintSlider = (val) => {
      scoreInput.style.background = `linear-gradient(to right, var(--brand) ${val}%, var(--line) ${val}%)`;
    };
    scoreInput.addEventListener("input", (e) => {
      const val = parseInt(e.target.value, 10);
      paintSlider(val);
      updateEstimator(val);
    });
    paintSlider(parseInt(scoreInput.value, 10));
    updateEstimator(parseInt(scoreInput.value, 10));
  }

  /* ---------- 5. Navbar scroll, Back-to-top & Mobile Sticky Bar ---------- */
  const nav = document.getElementById("ibNav");
  const toTop = document.getElementById("toTop");
  const mobileStickyBar = document.getElementById("mobileStickyBar");

  const onScroll = () => {
    const y = window.scrollY;
    if (nav) nav.classList.toggle("scrolled", y > 20);
    if (toTop) toTop.classList.toggle("show", y > 450);
    if (mobileStickyBar) mobileStickyBar.classList.toggle("visible", y > 400);
  };
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  /* ---------- 6. Close mobile menu after clicking a link ---------- */
  const menu = document.getElementById("ibMenu");
  document.querySelectorAll("#ibMenu .nav-link, #ibMenu .btn").forEach(link => {
    link.addEventListener("click", () => {
      if (menu && menu.classList.contains("show")) {
        bootstrap.Collapse.getOrCreateInstance(menu).hide();
      }
    });
  });

  /* ---------- 7. Highlight the section currently in viewport ---------- */
  const sections = document.querySelectorAll("section[id], header[id]");
  const navLinks = document.querySelectorAll('#ibMenu .nav-link[href^="#"]');

  if ("IntersectionObserver" in window && sections.length) {
    const spy = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        navLinks.forEach(a => {
          a.classList.toggle("active", a.getAttribute("href") === "#" + entry.target.id);
        });
      });
    }, { rootMargin: "-40% 0px -50% 0px" });
    sections.forEach(s => spy.observe(s));
  }

  /* ---------- 8. Animate stat numbers on scroll once ---------- */
  const formatNum = n => {
    if (n >= 100000) return (n / 100000).toFixed(1).replace(/\.0$/, "") + " Lacs+";
    if (n >= 1000) return Math.round(n).toLocaleString("en-IN") + "+";
    return Math.round(n) + "+";
  };

  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  const runCount = el => {
    const target = parseInt(el.dataset.count, 10);
    const suffix = el.dataset.suffix || "";

    if (reduceMotion) {
      el.textContent = suffix ? target + suffix : formatNum(target);
      return;
    }

    const duration = 1500;
    const start = performance.now();

    const tick = now => {
      const p = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - p, 3);
      const value = target * eased;
      el.textContent = suffix ? Math.round(value) + suffix : formatNum(value);
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  };

  const stats = document.querySelectorAll(".stat-num[data-count]");
  if ("IntersectionObserver" in window && stats.length) {
    const statObserver = new IntersectionObserver((entries, obs) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          runCount(entry.target);
          obs.unobserve(entry.target);
        }
      });
    }, { threshold: 0.3 });
    stats.forEach(s => statObserver.observe(s));
  } else {
    stats.forEach(runCount);
  }

  /* ---------- 9. Dynamic copyright year in footer ---------- */
  const year = document.getElementById("year");
  if (year) year.textContent = new Date().getFullYear();

  /* ---------- 10. Flow bridge pill scrolls to Step 4 ---------- */
  const bridgePill = document.querySelector(".flow-bridge-pill");
  if (bridgePill) {
    bridgePill.style.cursor = "pointer";
    bridgePill.addEventListener("click", () => {
      const step4 = document.querySelector('[data-flow-step="4"]');
      if (step4) step4.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  }

  //   /* ---------- 11. Expand / Collapse all FAQs ---------- */
  //   const faqBox = document.getElementById("faqBox");
  //   if (faqBox) {
  //     const toggleBtn = document.createElement("button");
  //     toggleBtn.type = "button";
  //     // toggleBtn.className = "btn btn-ib-ghost btn-sm faq-expand-all mb-3";
  //     toggleBtn.innerHTML = `<i class="bi bi-arrows-expand"></i> <span>Expand all</span>`;
  //     faqBox.parentElement.insertBefore(toggleBtn, faqBox);

  //     let expanded = false;
  //     toggleBtn.addEventListener("click", () => {
  //       expanded = !expanded;
  //       faqBox.querySelectorAll(".accordion-collapse").forEach(panel => {
  //         bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false })[expanded ? "show" : "hide"]();
  //       });
  //       toggleBtn.querySelector("span").textContent = expanded ? "Collapse all" : "Expand all";
  //     });
  //   }

  /* ---------- 12. FAQ Hover-to-Open / Hover-away-to-Close ---------- */
  (function () {
    const faqBox = document.getElementById("faqBox");
    if (!faqBox) return;

    const faqItems = faqBox.querySelectorAll(".accordion-item");
    let hoverTimer = null;
    let currentOpen = null;

    faqItems.forEach(item => {
      const collapseEl = item.querySelector(".accordion-collapse");
      if (!collapseEl) return;

      item.addEventListener("mouseenter", () => {
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(() => {
          // Close the previously opened item
          if (currentOpen && currentOpen !== collapseEl) {
            const prevInstance = bootstrap.Collapse.getInstance(currentOpen);
            if (prevInstance) prevInstance.hide();
          }
          // Open this item if not already open
          if (!collapseEl.classList.contains("show")) {
            let instance = bootstrap.Collapse.getInstance(collapseEl);
            if (!instance) instance = new bootstrap.Collapse(collapseEl, { toggle: false });
            instance.show();
          }
          currentOpen = collapseEl;
        }, 120); // 120ms hover intent delay
      });

      item.addEventListener("mouseleave", () => {
        clearTimeout(hoverTimer);
      });
    });

    // Close when cursor fully leaves the accordion
    faqBox.addEventListener("mouseleave", () => {
      clearTimeout(hoverTimer);
      hoverTimer = setTimeout(() => {
        if (currentOpen) {
          const instance = bootstrap.Collapse.getInstance(currentOpen);
          if (instance) instance.hide();
          currentOpen = null;
        }
      }, 200);
    });
  })();

  /* ---------- 13. Pattern Deck — Stacked → Spread on Hover ---------- */
  (function () {
    const wrap = document.getElementById("patternDeckWrap");
    const deck = document.getElementById("patternDeck");
    if (!wrap || !deck) return;

    wrap.addEventListener("mouseenter", () => {
      deck.classList.add("is-spread");
      wrap.classList.add("is-spread");
    });

    wrap.addEventListener("mouseleave", () => {
      deck.classList.remove("is-spread");
      wrap.classList.remove("is-spread");
    });
  })();

  /* ============================================================
     Assessment Gauge — Hero Right-Side Interactive Widget
     ============================================================ */
  (function () {
    /* Level definitions — edit here to update all labels/scores */
    var LEVELS = [
      { label: 'Top / Excellent',    range: '85 – 100%', pct: 90, lvlTxt: 'Level 1', icon: 'trophy-fill',      iconColor: '#ff8a1e' },
      { label: 'Intermediate',       range: '70 – 84%',  pct: 77, lvlTxt: 'Level 2', icon: 'star-fill',        iconColor: '#f59e0b' },
      { label: 'Basic / Employable', range: '55 – 69%',  pct: 62, lvlTxt: 'Level 3', icon: 'patch-check-fill', iconColor: '#1652d6' },
      { label: 'Basic Knowledge',    range: '40 – 54%',  pct: 47, lvlTxt: 'Level 4', icon: 'book-half',        iconColor: '#6366f1' },
      { label: 'Needs Training',     range: '0 – 39%',   pct: 20, lvlTxt: 'Level 5', icon: 'pencil-fill',      iconColor: '#94a3b8' }
    ];

    /* SVG constants: radius = 88, circumference = 2π×88 ≈ 553 */
    var CIRC = 553;

    var ring     = document.getElementById('gaugeRing');
    var pctEl    = document.getElementById('gaugePct');
    var labelEl  = document.getElementById('gaugeLabel');
    var lvlEl    = document.getElementById('gaugeLvl');
    var rangeEl  = document.getElementById('gaugeRange');
    var trophyEl = document.getElementById('gaugeTrophy');
    var btns     = document.querySelectorAll('.gauge-lvl-btn');

    if (!ring || !btns.length) return;

    var currentPct = 90;
    var pctTimer   = null;

    /* ---- smooth percentage counter ---- */
    function animatePct(from, to) {
      clearInterval(pctTimer);
      var start = performance.now();
      var dur   = 500;
      pctTimer  = setInterval(function () {
        var elapsed = performance.now() - start;
        var t       = Math.min(elapsed / dur, 1);
        var ease    = 1 - Math.pow(1 - t, 3);
        var val     = Math.round(from + (to - from) * ease);
        pctEl.textContent = val + '%';
        if (t >= 1) clearInterval(pctTimer);
      }, 16);
    }

    /* ---- activate a level ---- */
    function activate(idx) {
      var lvl    = LEVELS[idx];
      var offset = CIRC - (lvl.pct / 100) * CIRC;
      ring.style.strokeDashoffset = offset;

      animatePct(currentPct, lvl.pct);
      currentPct = lvl.pct;

      labelEl.textContent  = lvl.label;
      lvlEl.textContent    = lvl.lvlTxt;
      rangeEl.innerHTML    = '<i class="bi bi-graph-up-arrow"></i> ' + lvl.range;

      trophyEl.className   = 'bi bi-' + lvl.icon + ' gauge-center-trophy';
      trophyEl.style.color = lvl.iconColor;

      btns.forEach(function (btn, i) {
        btn.classList.remove('active', 'faded');
        btn.setAttribute('aria-pressed', i === idx ? 'true' : 'false');
        if (i !== idx) btn.classList.add('faded');
      });
      btns[idx].classList.add('active');
      btns[idx].classList.remove('faded');
    }

    /* ---- wire hover + click ---- */
    btns.forEach(function (btn) {
      var idx = parseInt(btn.getAttribute('data-lvl'), 10);
      btn.addEventListener('mouseenter', function () { activate(idx); });
      btn.addEventListener('click',      function () { activate(idx); });
    });

    /* reset to Level 1 when mouse leaves the gauge visual */
    var gaugeStage = document.querySelector('.gauge-stage') || document.querySelector('.gauge-card');
    if (gaugeStage) {
      gaugeStage.addEventListener('mouseleave', function () { activate(0); });
    }

    /* default state */
    activate(0);
  })();

});