# Evaluating the automated verification

Purpose: produce numbers for the capstone that show whether the automated checks agree with the MSWD social worker. Use the
**test instance only**, with made-up applicants and made-up or consenting documents. Never use real applicants' files.

## 1. Build the test set (about 40 applications)

Make each application on purpose, so you know the right answer in advance. Suggested mix:

| Group | Count | How to make it | Expected system result |
|---|---|---|---|
| A. Clean | 12 | Complete data, sharp photos, name visible on documents | ready |
| B. Blurry photo | 4 | One document photographed out of focus | review |
| C. Dark or washed-out photo | 3 | Photo in poor light, or overexposed | review |
| D. Tiny photo | 3 | Screenshot or very small image | review |
| E. Same file reused | 3 | Upload one image for two documents | review |
| F. Dependent too old | 2 | Child aged 25 | review |
| G. Child or birthdate impossible | 3 | Child born after the submission date, or before the parent | fix |
| H. Missing required document | 3 | Skip a required upload (only possible by editing the draft; or staff remove it) | fix |
| I. Wrong person's document | 4 | Upload a document in another person's name (needs OCR) | review |
| J. No children listed | 2 | Leave the child row empty | review |
| K. Possible duplicate | 1 | Two accounts, same name and birthdate | review |

Keep a spreadsheet: application reference, group, what you expect, what the system said.

## 2. Let staff decide independently

Ask a social worker (or the person acting as one) to review each application in the normal way **without** looking at the
automated card first: verify documents, request corrections, approve or reject. Record the outcome.

## 3. Collect the numbers

    php database/auto_verification_report.php

This prints, for the system's first recommendation on each application, how many ended approved, needed corrections, were rejected or are
still open, and three percentages (clean pass rate, problems caught, flags that were justified). Copy the table into the chapter.

By hand, from your spreadsheet, build the standard 2x2 table, with "problem" meaning staff requested corrections or rejected:

| | Staff found a problem | Staff found none |
|---|---|---|
| System flagged or failed | true positive (TP) | false alarm (FP) |
| System said ready | missed problem (FN) | true negative (TN) |

- Precision = TP / (TP + FP) (how often a flag was right)
- Recall = TP / (TP + FN) (how many real problems were caught)
- Accuracy = (TP + TN) / total

State the sample size next to every percentage.

## 4. Time saved (optional but persuasive)

Time the same 10 applications with the automated card hidden and shown (or compare with the old process), and report the average review time
for each. Also report how many applications the "ready" fast lane let staff approve with a single pass.

## 5. Tune, then re-measure

If recall is low, the image limits are too lenient; if false alarms are high, too strict. Adjust with
`php database/auto_verification_samples.php <folder>` (see `docs/automated-verification.md`), then repeat sections 1 to 3 with a **new** set
so you are not grading the system on the photos you tuned it with.

## 6. Other evidence panels usually ask for

- Usability: the System Usability Scale (10 questions) from 15 to 30 real users (applicants and staff).
- Quality characteristics: ISO/IEC 25010:2023 questionnaire (Part 3 of `docs/questionnaires/evaluation-questionnaire.html`): functional suitability, performance efficiency, reliability, security and interaction capability (the 2011 edition called it usability).
- SMS: a short test of real delivery through the live provider, with timestamps (the test provider only logs).

## Limits to write in the chapter

State that the checks detect inconsistencies and poor-quality files; they cannot prove a document is genuine; and that results come from a
test set you built, so they show the checks work as designed, not their accuracy on every real-world document.
