# Patchstack Annual Report — Verified Data Reference

*All numbers verified 2026-02-27 by reading the live Patchstack whitepaper pages directly in a browser. Chart data comes from canvas-rendered charts whose values are NOT present in DOM text — required browser screenshots to capture.*

---

## 2024 Report (2023 Data)

**Source:** https://patchstack.com/whitepaper/state-of-wordpress-security-in-2024/

- **Total:** 5,948 vulnerabilities (+24% from 2022)
- **Type breakdown (discovery):**
  - XSS: 53.3%
  - CSRF: 16.9%
  - Broken Access Control: 12.9%
- **2022 comparison:** XSS 27%, CSRF 29% — significant shift toward XSS dominance by 2023
- **No exploitation-by-type chart** in this report

---

## 2025 Report (2024 Data)

**Source:** https://patchstack.com/whitepaper/state-of-wordpress-security-in-2025/

- **Total:** 7,966 vulnerabilities (+34% from 2023)
- **Type breakdown (discovery, from canvas chart — counts verified against 7,966 total):**

| Type | Count | Percentage |
|------|------:|----------:|
| XSS | 3,800 | 47.70% |
| Broken Access Control | 1,130 | 14.19% |
| CSRF | 904 | 11.35% |
| SQL Injection | 405 | 5.08% |
| Sensitive Data Exposure | 342 | 4.29% |
| Arbitrary File Upload | 229 | 2.87% |
| Local File Inclusion | 174 | 2.18% |
| PHP Object Injection | 161 | 2.02% |
| Privilege Escalation | 126 | 1.58% |
| Broken Authentication | 78 | 0.98% |

- **Sudo-mitigated classes** (BAC + CSRF + PrivEsc + BrokenAuth) = 2,238 = **28.1%**
- **No exploitation-by-type chart** in this report

---

## 2026 Report (2025 Data)

**Source:** https://patchstack.com/whitepaper/state-of-wordpress-security-in-2026/

### Totals

- **Total:** 11,334 vulnerabilities (+42% from 2024)
  - Note: report text says 11,334; chart label shows 11,332 (minor internal inconsistency — using prose figure as primary)
- **By component:** Plugin 10,359, Theme 971, Core 2
- **Premium components:** 1,983 total, 76% exploitable
- **No discovery-by-type breakdown** for 2025 data (unlike the 2024 and 2025 report editions, which covered 2023 and 2024 data respectively)

### Exploitation Targeting (NEW — RapidMitigate blocked attack data)

This is the first Patchstack report to provide exploitation-by-type data (what attackers actually target), as opposed to discovery data (what gets filed). The chart is titled "Most exploited vulnerabilities (blocked by RapidMitigate)."

| Type | Share of exploitation attempts |
|------|------------------------------:|
| Broken Access Control | 57% |
| Privilege Escalation | 20% |
| Local File Inclusion | 10% |
| SQL Injection | 5% |
| Broken Authentication | 3% |
| Arbitrary File Upload | 3% |
| Remote Code Execution | 1% |
| XSS | 1% |

- **Sudo-mitigated classes** (BAC + PrivEsc + BrokenAuth) = **80%** of all exploitation attempts
  - Note: CSRF does not appear in the exploitation data because RapidMitigate tracks automated attack attempts; CSRF requires user interaction (e.g., tricking an admin into clicking a crafted link)

### Key Statistics

- Highly exploitable vulnerabilities increased **113% YoY**
- **46%** of vulnerabilities had no developer fix at time of public disclosure
- **Approximately half** of high-impact vulnerabilities exploited within 24 hours; weighted median time to first exploit was **5 hours**
  - Note: "approximately half" is the report's own language — not "45%"

### WAF Testing (Two Separate Experiments)

The report describes two distinct WAF evaluation experiments. The 12% and 26% figures are **aggregates across all non-Patchstack hosts**, NOT a range:

| Experiment | Scope | Aggregate block rate |
|-----------|-------|--------------------:|
| First | Known exploited vulnerabilities only | 12% |
| Second | Expanded vulnerability scope | 26% |

- **Per-host range:** 13.8% to 60.7% across individual non-Patchstack WAF hosts (from canvas chart)
- **Patchstack control group:** 93.3%

---

## Cross-Report Trends

| Metric | 2023 | 2024 | 2025 |
|--------|-----:|-----:|-----:|
| Total vulnerabilities | 5,948 | 7,966 | 11,334 |
| YoY growth | +24% | +34% | +42% |
| BAC (discovery share) | 12.9% | 14.19% | N/A |
| XSS (discovery share) | 53.3% | 47.70% | N/A |
| BAC (exploitation share) | N/A | N/A | 57% |
| XSS (exploitation share) | N/A | N/A | 1% |

**Key insight:** BAC is 14% of what's *filed* but 57% of what's *attacked*. XSS is 48% of what's filed but only 1% of what's attacked. Attackers use XSS as a stepping stone (session hijacking → access control exploitation), not as an end in itself.

---

## Relevance to WP Sudo

The discovery-vs-exploitation distinction fundamentally changes the risk reduction narrative:

- **Discovery basis:** Sudo-mitigated classes = ~28% of all WordPress vulnerabilities
- **Exploitation basis:** Sudo-mitigated classes = 80% of all WordPress exploitation attempts
- The ~28% figure dramatically undersells real-world risk reduction
- BAC, PrivEsc, and BrokenAuth are the vulnerability types attackers overwhelmingly target — and they are exactly the types Sudo gates

### Where these numbers appear in the docs

- `docs/security-model.md` — "Public data supporting risk reduction" section, risk reduction estimates table
- `FAQ.md` — opening "What problem does Sudo solve?" section, "Why this matters by the numbers" paragraph
