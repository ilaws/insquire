-- Replaces the generic template questions on the core 6-skill state lessons with topic-specific questions.
-- Safe to re-run.

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'Which sentence states a central idea rather than a supporting detail?', q.options_json = '["Regular exercise improves both physical and mental health.", "One study tracked fifty runners for six weeks.", "The park added a new running trail last year.", "She jogs every Tuesday and Thursday."]', q.correct_answer = 'Regular exercise improves both physical and mental health.', q.explanation = 'A central idea is the broad point the details are there to support.'
WHERE ls.skill = 'Central idea and summarizing' AND q.sort_order = 1 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'A paragraph explains three causes of soil erosion. Which is the best one-sentence summary?', q.options_json = '["Soil erosion has several causes, including water runoff, wind, and deforestation.", "Water runoff is one cause of many problems.", "Deforestation is bad for the environment in general.", "The paragraph lists three things."]', q.correct_answer = 'Soil erosion has several causes, including water runoff, wind, and deforestation.', q.explanation = 'A strong summary names the actual causes, not a vague reference to \'things.\''
WHERE ls.skill = 'Central idea and summarizing' AND q.sort_order = 2 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'What is the key difference between a central idea and an opinion?', q.options_json = '["The central idea comes from the text; an opinion is the reader\'s own view.", "They are always exactly the same thing.", "A central idea is only found in the last sentence.", "An opinion should replace the central idea in a summary."]', q.correct_answer = 'The central idea comes from the text; an opinion is the reader\'s own view.', q.explanation = 'Summaries stay grounded in what the text actually says, not the reader\'s reaction to it.'
WHERE ls.skill = 'Central idea and summarizing' AND q.sort_order = 3 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'A good summary of an article should:', q.options_json = '["Include only the most important points, in your own words.", "Copy the first paragraph exactly as written.", "Add your personal opinion about every fact.", "List every small detail in the order given."]', q.correct_answer = 'Include only the most important points, in your own words.', q.explanation = 'Summarizing means condensing to what matters most, restated rather than copied.'
WHERE ls.skill = 'Central idea and summarizing' AND q.sort_order = 4 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'short_response', q.question_text = 'In two or three sentences, summarize the central idea of a text your class is reading, using your own words and one supporting detail.', q.options_json = NULL, q.correct_answer = NULL, q.explanation = 'A strong summary states the central idea clearly and backs it with one specific, relevant detail from the text.'
WHERE ls.skill = 'Central idea and summarizing' AND q.sort_order = 5 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'Which best defines theme?', q.options_json = '["A lesson or insight about life that a story reveals.", "The name of the main character.", "A one-word topic like \'love\' or \'war.\'", "The setting where the story takes place."]', q.correct_answer = 'A lesson or insight about life that a story reveals.', q.explanation = 'Theme is a full insight, not a single topic word or a plot fact.'
WHERE ls.skill = 'Theme analysis' AND q.sort_order = 1 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'A student claims the theme of a story is simply \'family.\' What is missing?', q.options_json = '["A full statement of the insight about family, not just a topic word.", "Nothing \u2014 that is already a complete theme.", "The page number where family is mentioned.", "A dictionary definition of the word family."]', q.correct_answer = 'A full statement of the insight about family, not just a topic word.', q.explanation = 'A real theme statement explains what the text says about the topic, not just names it.'
WHERE ls.skill = 'Theme analysis' AND q.sort_order = 2 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'Which detail is the strongest evidence for a theme about courage?', q.options_json = '["The character finally speaks up even though her hands are shaking.", "The character has brown hair and green eyes.", "The story takes place in early autumn.", "The book is exactly two hundred pages long."]', q.correct_answer = 'The character finally speaks up even though her hands are shaking.', q.explanation = 'Evidence for theme should show a meaningful choice or change connected to the idea, not a neutral fact.'
WHERE ls.skill = 'Theme analysis' AND q.sort_order = 3 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'How does a theme usually develop across a story?', q.options_json = '["Gradually, through characters\' choices and conflict over time.", "Instantly, in the very first sentence.", "Only in a summary written after the story ends.", "It never changes or develops at all."]', q.correct_answer = 'Gradually, through characters\' choices and conflict over time.', q.explanation = 'Strong theme analysis traces how the idea builds from the beginning to the end of the text.'
WHERE ls.skill = 'Theme analysis' AND q.sort_order = 4 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'short_response', q.question_text = 'Name one theme from a text you\'ve read. Describe one moment early in the story and one moment later that both connect to this theme.', q.options_json = NULL, q.correct_answer = NULL, q.explanation = 'A strong response points to two specific, different moments and explains how each develops the same theme.'
WHERE ls.skill = 'Theme analysis' AND q.sort_order = 5 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'What is an inference?', q.options_json = '["A logical conclusion based on text evidence plus reasoning.", "A random guess with no evidence at all.", "A fact the text states directly and plainly.", "The title printed at the top of the passage."]', q.correct_answer = 'A logical conclusion based on text evidence plus reasoning.', q.explanation = 'An inference always combines a specific detail with reasoning, not just a guess.'
WHERE ls.skill = 'Inference and citing evidence' AND q.sort_order = 1 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'Which response best supports an inference in writing?', q.options_json = '["A specific text detail plus an explanation connecting it to the conclusion.", "Just stating the conclusion, with no text reference at all.", "A quotation with no explanation of why it matters.", "A personal opinion unrelated to the text."]', q.correct_answer = 'A specific text detail plus an explanation connecting it to the conclusion.', q.explanation = 'Both parts, evidence and explanation, are required to make an inference convincing.'
WHERE ls.skill = 'Inference and citing evidence' AND q.sort_order = 2 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'A character locks the door twice and checks the window before bed. What can you reasonably infer?', q.options_json = '["The character feels unsafe or afraid of something.", "The character simply enjoys locking doors.", "The character is getting ready for a party.", "The house described has no doors."]', q.correct_answer = 'The character feels unsafe or afraid of something.', q.explanation = 'The repeated, careful action is a clue pointing to fear or unease, even though the text never says so directly.'
WHERE ls.skill = 'Inference and citing evidence' AND q.sort_order = 3 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'What is the difference between what a text says and what a reader infers?', q.options_json = '["What it says is stated directly; an inference is a reasoned conclusion beyond the literal words.", "There is no real difference between the two.", "Inference only applies to nonfiction writing.", "What a text says is always false."]', q.correct_answer = 'What it says is stated directly; an inference is a reasoned conclusion beyond the literal words.', q.explanation = 'Separating stated facts from reasoned conclusions keeps analysis honest and evidence-based.'
WHERE ls.skill = 'Inference and citing evidence' AND q.sort_order = 4 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'short_response', q.question_text = 'Write one inference about a character or claim in a text you\'re reading. Name the specific evidence and explain the reasoning that connects it to your conclusion.', q.options_json = NULL, q.correct_answer = NULL, q.explanation = 'A strong response cites a specific detail and clearly explains the reasoning step from that detail to the conclusion.'
WHERE ls.skill = 'Inference and citing evidence' AND q.sort_order = 5 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'The best way to figure out an unfamiliar word\'s meaning is to:', q.options_json = '["Read the surrounding sentences for context clues before checking a dictionary.", "Skip the word entirely and keep reading.", "Guess based only on the word\'s first letter.", "Assume it means the same as a similar-looking word."]', q.correct_answer = 'Read the surrounding sentences for context clues before checking a dictionary.', q.explanation = 'Context almost always narrows down meaning before you even need a dictionary.'
WHERE ls.skill = 'Vocabulary in context' AND q.sort_order = 1 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'In \'The arid desert received almost no rainfall for the entire year,\' what does \'arid\' most likely mean?', q.options_json = '["Extremely dry", "Very cold", "Densely forested", "Highly populated"]', q.correct_answer = 'Extremely dry', q.explanation = 'The clue \'almost no rainfall\' directly points to a dry climate.'
WHERE ls.skill = 'Vocabulary in context' AND q.sort_order = 2 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'Which of these counts as a context clue?', q.options_json = '["A definition, example, or contrast given near the unfamiliar word.", "The number of letters in the word.", "The color of the book\'s cover.", "The chapter number the word appears in."]', q.correct_answer = 'A definition, example, or contrast given near the unfamiliar word.', q.explanation = 'Context clues are meaning-related signals in the surrounding text, not unrelated features.'
WHERE ls.skill = 'Vocabulary in context' AND q.sort_order = 3 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'After guessing a word\'s meaning from context, what should you do next?', q.options_json = '["Substitute your guess back into the sentence to see if it still makes sense.", "Never revisit or check the guess again.", "Assume the guess is correct with no check at all.", "Change the sentence so it fits your guess."]', q.correct_answer = 'Substitute your guess back into the sentence to see if it still makes sense.', q.explanation = 'Testing the guess in place is a quick, reliable way to confirm it fits.'
WHERE ls.skill = 'Vocabulary in context' AND q.sort_order = 4 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'short_response', q.question_text = 'Choose one unfamiliar word from your reading. Write the context clue that helped you determine its meaning, then use the word correctly in your own sentence.', q.options_json = NULL, q.correct_answer = NULL, q.explanation = 'A strong response identifies a real clue from the text and shows correct usage in an original sentence.'
WHERE ls.skill = 'Vocabulary in context' AND q.sort_order = 5 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'Which signal words most often suggest a cause-and-effect structure?', q.options_json = '["Because, therefore, as a result", "First, next, finally", "Similarly, in contrast", "For example, such as"]', q.correct_answer = 'Because, therefore, as a result', q.explanation = 'These words directly signal a reason-and-result relationship between ideas.'
WHERE ls.skill = 'Text structure and organization' AND q.sort_order = 1 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'A text spends one section on the benefits of solar power and another on the benefits of wind power, matching each point across both. What structure is this?', q.options_json = '["Comparison", "Cause and effect", "Chronological sequence", "Problem and solution"]', q.correct_answer = 'Comparison', q.explanation = 'Presenting matched points about two subjects side by side is a comparison structure.'
WHERE ls.skill = 'Text structure and organization' AND q.sort_order = 2 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'Why might an author choose a problem-and-solution structure?', q.options_json = '["To build toward a proposed fix for an issue they\'ve described.", "To confuse the reader on purpose.", "Because every text is required to use it.", "To avoid using any transition words."]', q.correct_answer = 'To build toward a proposed fix for an issue they\'ve described.', q.explanation = 'This structure is chosen deliberately to move a reader from an issue toward a resolution.'
WHERE ls.skill = 'Text structure and organization' AND q.sort_order = 3 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'Which of these is NOT a common nonfiction text structure?', q.options_json = '["Alphabetical listing", "Cause and effect", "Comparison", "Chronological sequence"]', q.correct_answer = 'Alphabetical listing', q.explanation = 'Alphabetical order organizes by letter, not by the logical relationships that define real text structures.'
WHERE ls.skill = 'Text structure and organization' AND q.sort_order = 4 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'short_response', q.question_text = 'Identify the structure of a text you\'re reading (sequence, cause/effect, comparison, or problem/solution) and explain in one or two sentences how that structure supports the author\'s purpose.', q.options_json = NULL, q.correct_answer = NULL, q.explanation = 'A strong response names a specific structure and explains its purpose rather than just labeling it.'
WHERE ls.skill = 'Text structure and organization' AND q.sort_order = 5 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'A strong thesis or claim should be:', q.options_json = '["Specific and arguable, something a reasonable person could disagree with.", "A simple fact nobody could question.", "As vague and general as possible.", "Written as a question instead of a statement."]', q.correct_answer = 'Specific and arguable, something a reasonable person could disagree with.', q.explanation = 'A claim only works as an argument if it\'s specific enough to actually be debated.'
WHERE ls.skill = 'Argument writing and evidence use' AND q.sort_order = 1 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'Evidence in an argument is most persuasive when it is:', q.options_json = '["Relevant to the claim and explained, not just listed.", "As long as possible, regardless of relevance.", "Copied in with no explanation at all.", "Interesting but unrelated to the claim."]', q.correct_answer = 'Relevant to the claim and explained, not just listed.', q.explanation = 'Evidence only persuades once the writer explains how it connects back to the claim.'
WHERE ls.skill = 'Argument writing and evidence use' AND q.sort_order = 2 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'Why should a writer address a counterclaim?', q.options_json = '["It shows they\'ve considered other viewpoints and can still defend their position.", "It weakens their own argument on purpose.", "It\'s only needed to reach a word count.", "It replaces the need for evidence entirely."]', q.correct_answer = 'It shows they\'ve considered other viewpoints and can still defend their position.', q.explanation = 'Addressing a counterclaim makes an argument more credible, not weaker.'
WHERE ls.skill = 'Argument writing and evidence use' AND q.sort_order = 3 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'multiple_choice', q.question_text = 'Which is the strongest example of relevant evidence for the claim \'School start times should be later\'?', q.options_json = '["A study showing teenagers perform better academically with later start times.", "A story about a fun class field trip.", "The name of the school\'s mascot.", "An opinion about weekend plans."]', q.correct_answer = 'A study showing teenagers perform better academically with later start times.', q.explanation = 'Relevant evidence directly supports the specific claim being made.'
WHERE ls.skill = 'Argument writing and evidence use' AND q.sort_order = 4 AND ls.state_code NOT IN ('SKILL','CCSS');

UPDATE lesson_questions q
JOIN lessons l ON l.id = q.lesson_id
JOIN lesson_standards ls ON ls.id = l.standard_id
SET q.question_type = 'short_response', q.question_text = 'State a claim about a topic. List one piece of evidence that supports it, then write one sentence addressing a possible counterclaim.', q.options_json = NULL, q.correct_answer = NULL, q.explanation = 'A strong response includes a clear, specific claim, relevant evidence, and a genuine counterclaim response.'
WHERE ls.skill = 'Argument writing and evidence use' AND q.sort_order = 5 AND ls.state_code NOT IN ('SKILL','CCSS');
