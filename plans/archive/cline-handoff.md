# Cline hand-off: department UI redesign

Open this folder in VS Code, open the Cline panel, choose provider **DeepSeek**, model **DeepSeek V4 Pro**. Start a **new task for every phase** (keeps the context small). Paste the preamble, then one phase line.

## Before you start
- Cline settings: allow it to read and edit files and run commands in this folder; leave **browser actions** on manual approval; do not auto-approve anything that opens `http://127.0.0.1:8085` and clicks.
- The app must be running at http://127.0.0.1:8085 (it is started by `start.bat`). Cline must not stop it.
- Commit and push are part of each phase (`origin main`). Check the diff in VS Code before you accept the push if you want a review gate.

## Preamble (paste at the top of every task)
```
Read @plans/archive/ui-redesign-plan.md. Follow its "Safety rules" and "Constraints" exactly.
Implement only the phase named below. Do not explore beyond the files listed in that phase.
Never press an action button in the live app (they send email and post to Facebook); only load pages.
Never edit or delete anything in data/. Keep the server on 127.0.0.1:8085 running.
When done: run all tests (2 failures in tests/test_measure.php are known and not yours), commit with a clear message, push to origin main, then stop and report in 5 lines: what changed, test result, the numbers the phase asks you to measure.
If something is missing or contradicts the code, stop and say exactly what.
```

## Phase lines (one per task)
1. `Phase 0 - guard tests and baseline (no visual change).`
2. `Phase 1 - tokens and mechanical snapping.`
3. `Phase 2 - collapse the CSS layers, batches of at most 5 selectors, tests after each batch.`
4. `Phase 3 - shell: top bar, page head, tabs. Report the four measured heights.`
5. `Phase 4 - components, one commit per component in the listed order.`
6. `Phase 5, part 1 - view_home.php, view_agents.php, view_social.php.`
7. `Phase 5, part 2 - view_social_optimise.php with pm_opt_item_html in lib/sx_optimise.php, view_social_studio.php, view_social_kit.php.`
8. `Phase 5, part 3 - view_social_page.php, view_social_channels.php, view_social_leadposts.php.`
9. `Phase 5, part 4 - view_social_content.php, view_social_growth.php, view_social_results.php, view_social_numbers.php.`
10. `Phase 5, part 5 - view_social_voice.php, view_sales_targeting.php, view_whatsapp.php, view_outbound.php, view_drafts.php.`
11. `Phase 5, part 6 - all view_ops_*.php, view_settings.php, view_business.php, then the proposal tabs in index.php.`
12. `Phase 6 - delete dead CSS and the temporary token aliases.`
13. `Phase 7 - responsive and accessibility, check at 375, 768, 1280 and 1560 px.`
14. `Phase 8 - UI.md, README.txt pointer, final test run, move the plan to plans/archive/.`

## Review gate after each phase (you or Claude)
- `php tests/test_ui_system.php` (budgets must not be worse; after Phase 5 they must be 0 for the files done).
- Load the QA pages listed at the end of the plan at 1280px and 375px.
- If a phase went wrong: `git revert <commit>` and rerun that phase with the problem named.
