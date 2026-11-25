# English diagnostics

The Education workspace includes an optional 25-question English diagnostic for students. Students can take it as a self-check or complete one assigned by a teacher. Teachers can assign a check to a class, set an optional due date, close or reopen it, and see completed scores and skill-area summaries.

The diagnostic checks grammar and sentence use, vocabulary in context, and reading comprehension. It gives an approximate A1–C1 CEFR estimate and uses no AI service, so taking it does not consume an AI request or send student answers to a model. Each class assignment allows one saved attempt per student; students may take the self-check more than once.

This is a short, low-stakes screening tool, not a validated or certified CEFR exam. It does not measure speaking, listening, extended writing, or all relevant English skills. Treat its score as one piece of evidence for a teacher-student conversation, not as the sole basis for grading, placement, or access to instruction.

## Deployment

Run `database/english_diagnostics.sql` against the configured application database before deploying the PHP files. The maintainer's private deployment script applies this additive, repeatable schema before installing the student and teacher pages.

The reference project [english-level-test](https://github.com/merzann/english-level-test) was consulted for the general idea of a multiple-choice level check. InsQuire's questions and passages are original; the reference project's code and question content are not bundled.
