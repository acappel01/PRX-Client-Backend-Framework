# Quiz Module — Operator Guide

> **Scope note.** This guide currently covers the **Results page** only — the words a visitor
> reads after they finish the quiz. The rest of the quiz builder (steps, questions, branching,
> consent copy) is not yet documented; that gap predates this page and is worth closing.

## Overview

The intake quiz asks a visitor a few questions and then shows them a **plan**: the products and
packages that match the goals they picked, filtered by what is safe for their sex and age.

The plan lives at a private link of the form `/plan/{a long code}`. That link is how the
visitor returns to their results, and it is what a plan email points at. Treat it as private —
anyone holding the link can see that plan.

**You do not choose what is recommended here.** The matches come from the ingredient and health
goal mappings in the catalog. What you control on this page is what the visitor is *told*.

**A product adds straight to the cart from this page; a package sends the visitor to its own
page to choose a term.** The card quotes "as low as $X", and for a package that figure is
usually a monthly plan the visitor has not agreed to — adding it for them would start a
recurring charge they did not choose, and adding the package without it would charge more than
the card said. Neither is acceptable, so picking a term happens where the terms are visible. A
product has no term to pick, so it still adds in one tap and several can go in the bag without
leaving the page.

This is an interim: the intended design is a plan picker on this page, so a package can be
added without losing the report.

**How it looks follows your theme**, not this page — see **Settings → Theme** for colours,
fonts and the colour palette. There are no per-section layout controls here, because the plan
page is part of the application rather than a page you build in the page builder.

---

## Where to find it

**Quizzes → (choose a quiz) → Results page**

Five fields. Every one of them is optional: **leave a field blank and that part of the page
simply does not appear.** Nothing is invented to fill a gap.

Changes take effect immediately — the plan page reads these live, with no deploy and no cache
to wait out.

---

## The five fields

### Heading

Sits above the results. The visitor's first name is already shown separately above this, so it
does not need to greet them.

**It appears in every state**, including both "nothing to show" cases below. So a heading
worded as a promise — *"Your recommended protocol"* — will read badly above *"we're still
building this out"*. Something neutral, like *"Your results"*, travels across all of them.

### Intro — when there is something to recommend

Shown **only** when at least one goal matched. Because it can never appear above an empty
report, it is safe for this to promise results.

### When a goal has nothing suitable for this person

We stock this goal, but everything mapped to it is excluded for this visitor's sex or age.
**They were ruled out.**

Write this as what to do next, not as an explanation of why. Do not describe which ingredient
or which rule excluded them — the site deliberately never reveals that, because varying an
answer would otherwise let anyone map out which substances are sex- or age-gated.

### When a goal has not been built out yet

**Nobody** has mapped anything to this goal, for any visitor. This is an inventory gap on our
side, not a judgement about this person.

### When there are no answers to build a plan from

They reached the page without completing the quiz, or every goal they picked has since been
withdrawn.

---

## The two "nothing to show" cases are different, and the difference matters

This is the part most worth getting right.

| The visitor sees | What is actually true | What you must not say |
|---|---|---|
| *"nothing suitable for this person"* | We built this goal out. It is not for them. | "We're still building this" — false, and it hides a real exclusion |
| *"not built out yet"* | We have not built it, for anyone. | "Nothing is suitable for you" — implies they were assessed and rejected |

The system tells these apart for you and picks the right field. You only have to write two
honest sentences rather than one that tries to cover both.

**A visitor matching nothing is a normal outcome, not a fault.** Eligibility is set per
ingredient, so depending on how your catalog is mapped there will be combinations of goal, sex
and age that genuinely match nothing. That page still has to be worth reading.

---

## What this page does not control

- **Whether a plan email is sent.** That is **Settings → Communication → Email enabled**, and it
  is off unless you turn it on. The plan page will not promise a visitor an email when sending
  is switched off — it stays silent rather than making a promise the system cannot keep.
- **A PDF of the report.** Not built yet. There is deliberately no button for it, rather than a
  dead one.
- **Which products match.** Set that in the catalog, on the ingredient and health goal mappings.
- **The button labels** on the recommendation cards ("Add to cart") and the page's own
  headings. Those are still fixed in the site's code today.

---

## Tips

- Write the two empty-state fields **first**. They are the ones nobody reviews and the ones a
  real person is most likely to hit.
- Preview by opening a real plan link from **Leads → (a lead)** rather than guessing — the page
  differs by which of the states the visitor landed in.
- Keep the intro short. It sits between the visitor and the recommendations they came for.
