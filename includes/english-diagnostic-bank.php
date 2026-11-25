<?php

const ENGLISH_DIAGNOSTIC_PASSAGES = [
    'notice' => 'The school library will close at 4:00 p.m. on Thursday so staff can prepare for the weekend book sale. Students who need to return books may use the drop box beside the main entrance.',
    'research' => 'A small study compared two groups of students who reviewed new words in different ways. One group reread a word list; the other tried to recall each meaning before checking the list. After a week, the second group remembered more words. The researchers cautioned that the study lasted only seven days and involved students from one school, so the results may not apply to every learner.',
    'article' => 'When a city replaced several car parking spaces with shaded benches and trees, the change drew mixed reactions. Nearby shop owners first worried that fewer parking spaces would mean fewer customers. Six months later, a survey found that many visitors stayed longer in the area, though the survey did not establish whether the new seating caused the increase. The city plans to collect sales and transport data before deciding whether to redesign other streets.'
];

const ENGLISH_DIAGNOSTIC_QUESTIONS = [
    [
        'id' => 'q01', 'skill' => 'Grammar and use', 'prompt' => 'My brother ___ at home today.',
        'choices' => ['a' => 'am', 'b' => 'is', 'c' => 'are', 'd' => 'be'], 'answer' => 'b'
    ],
    [
        'id' => 'q02', 'skill' => 'Vocabulary', 'prompt' => 'Which word means the opposite of “early”?',
        'choices' => ['a' => 'late', 'b' => 'quick', 'c' => 'ready', 'd' => 'short'], 'answer' => 'a'
    ],
    [
        'id' => 'q03', 'skill' => 'Reading', 'passage' => 'notice', 'prompt' => 'Why will the library close early on Thursday?',
        'choices' => ['a' => 'A class is visiting.', 'b' => 'Staff are preparing a sale.', 'c' => 'The books are being returned.', 'd' => 'The building needs repairs.'], 'answer' => 'b'
    ],
    [
        'id' => 'q04', 'skill' => 'Grammar and use', 'prompt' => 'We ___ dinner at six yesterday.',
        'choices' => ['a' => 'eat', 'b' => 'eats', 'c' => 'ate', 'd' => 'eating'], 'answer' => 'c'
    ],
    [
        'id' => 'q05', 'skill' => 'Vocabulary', 'prompt' => 'A “quiet” room is a room with…',
        'choices' => ['a' => 'little noise', 'b' => 'many people', 'c' => 'bright lights', 'd' => 'large windows'], 'answer' => 'a'
    ],
    [
        'id' => 'q06', 'skill' => 'Grammar and use', 'prompt' => 'There ___ two pencils on the desk.',
        'choices' => ['a' => 'is', 'b' => 'are', 'c' => 'was', 'd' => 'be'], 'answer' => 'b'
    ],
    [
        'id' => 'q07', 'skill' => 'Reading', 'passage' => 'notice', 'prompt' => 'Where can a student return a book after the library closes?',
        'choices' => ['a' => 'At the book sale', 'b' => 'In the main office', 'c' => 'In the entrance drop box', 'd' => 'At a classroom door'], 'answer' => 'c'
    ],
    [
        'id' => 'q08', 'skill' => 'Grammar and use', 'prompt' => 'If it rains tomorrow, we ___ the game indoors.',
        'choices' => ['a' => 'move', 'b' => 'moved', 'c' => 'will move', 'd' => 'would moved'], 'answer' => 'c'
    ],
    [
        'id' => 'q09', 'skill' => 'Vocabulary', 'prompt' => 'Mina “borrowed” a book from the library. What will she probably do later?',
        'choices' => ['a' => 'Return it', 'b' => 'Sell it', 'c' => 'Print it', 'd' => 'Lose it'], 'answer' => 'a'
    ],
    [
        'id' => 'q10', 'skill' => 'Reading', 'passage' => 'research', 'prompt' => 'What did the students who recalled meanings do before checking the list?',
        'choices' => ['a' => 'They copied each word.', 'b' => 'They tried to remember each meaning.', 'c' => 'They studied for seven hours.', 'd' => 'They asked another school for help.'], 'answer' => 'b'
    ],
    [
        'id' => 'q11', 'skill' => 'Grammar and use', 'prompt' => 'I have lived in this town ___ 2022.',
        'choices' => ['a' => 'for', 'b' => 'since', 'c' => 'during', 'd' => 'from'], 'answer' => 'b'
    ],
    [
        'id' => 'q12', 'skill' => 'Vocabulary', 'prompt' => 'If a plan is “likely” to work, it is…',
        'choices' => ['a' => 'impossible', 'b' => 'probable', 'c' => 'finished', 'd' => 'expensive'], 'answer' => 'b'
    ],
    [
        'id' => 'q13', 'skill' => 'Grammar and use', 'prompt' => 'The article ___ by a student last week.',
        'choices' => ['a' => 'writes', 'b' => 'wrote', 'c' => 'was written', 'd' => 'has writing'], 'answer' => 'c'
    ],
    [
        'id' => 'q14', 'skill' => 'Reading', 'passage' => 'research', 'prompt' => 'Why do the researchers say the result may not apply to every learner?',
        'choices' => ['a' => 'The study was short and used students from one school.', 'b' => 'Neither group studied any words.', 'c' => 'The students changed schools.', 'd' => 'The study measured only reading speed.'], 'answer' => 'a'
    ],
    [
        'id' => 'q15', 'skill' => 'Vocabulary', 'prompt' => 'In a report, “evidence” is information that…',
        'choices' => ['a' => 'supports or challenges a claim', 'b' => 'decorates the page', 'c' => 'replaces the conclusion', 'd' => 'is always an opinion'], 'answer' => 'a'
    ],
    [
        'id' => 'q16', 'skill' => 'Grammar and use', 'prompt' => 'The teacher asked whether we ___ the instructions.',
        'choices' => ['a' => 'understand', 'b' => 'understood', 'c' => 'have understand', 'd' => 'are understood'], 'answer' => 'b'
    ],
    [
        'id' => 'q17', 'skill' => 'Reading', 'passage' => 'article', 'prompt' => 'What was one concern shop owners had at first?',
        'choices' => ['a' => 'Visitors would stay too long.', 'b' => 'Fewer parking spaces might reduce customers.', 'c' => 'The trees would block the shops.', 'd' => 'The survey would measure transport.'], 'answer' => 'b'
    ],
    [
        'id' => 'q18', 'skill' => 'Vocabulary', 'prompt' => 'A “cautious” conclusion is one that…',
        'choices' => ['a' => 'avoids claiming more than the evidence shows', 'b' => 'ignores all evidence', 'c' => 'makes a decision immediately', 'd' => 'uses the longest words'], 'answer' => 'a'
    ],
    [
        'id' => 'q19', 'skill' => 'Grammar and use', 'prompt' => 'If the team ___ the data earlier, it could have spotted the error.',
        'choices' => ['a' => 'checked', 'b' => 'had checked', 'c' => 'would check', 'd' => 'has checked'], 'answer' => 'b'
    ],
    [
        'id' => 'q20', 'skill' => 'Reading', 'passage' => 'article', 'prompt' => 'What can be concluded from the visitor survey?',
        'choices' => ['a' => 'The benches definitely caused sales to rise.', 'b' => 'Some visitors stayed longer, but the cause is uncertain.', 'c' => 'Every shop owner supported the change.', 'd' => 'The city has decided to redesign all streets.'], 'answer' => 'b'
    ],
    [
        'id' => 'q21', 'skill' => 'Grammar and use', 'prompt' => 'Choose the clearest sentence.',
        'choices' => ['a' => 'Although the sample was small, the researchers reported the limitation.', 'b' => 'Although the sample was small, but the researchers reported the limitation.', 'c' => 'The researchers although reported limitation small.', 'd' => 'Despite the sample was small, the researchers reported the limitation.'], 'answer' => 'a'
    ],
    [
        'id' => 'q22', 'skill' => 'Vocabulary', 'prompt' => 'In research, “to account for a result” most nearly means to…',
        'choices' => ['a' => 'explain it', 'b' => 'count it twice', 'c' => 'remove it', 'd' => 'predict its date'], 'answer' => 'a'
    ],
    [
        'id' => 'q23', 'skill' => 'Reading', 'passage' => 'article', 'prompt' => 'Why is the city collecting sales and transport data?',
        'choices' => ['a' => 'To decide whether to make similar street changes elsewhere.', 'b' => 'To prove that the survey was incorrect.', 'c' => 'To identify which visitors used the benches.', 'd' => 'To end the book sale.'], 'answer' => 'a'
    ],
    [
        'id' => 'q24', 'skill' => 'Grammar and use', 'prompt' => 'The findings are suggestive; ___, the limited sample prevents a firm conclusion.',
        'choices' => ['a' => 'therefore', 'b' => 'nevertheless', 'c' => 'for example', 'd' => 'in contrast to'], 'answer' => 'b'
    ],
    [
        'id' => 'q25', 'skill' => 'Reading', 'passage' => 'research', 'prompt' => 'Which statement best reflects the researchers’ caution?',
        'choices' => ['a' => 'Recall practice is proven to work for every age group.', 'b' => 'The study suggests a benefit, but further evidence is needed.', 'c' => 'Rereading never helps anyone learn.', 'd' => 'A week is enough to establish a universal rule.'], 'answer' => 'b'
    ]
];

function englishDiagnosticLevel(int $score): array
{
    return match (true) {
        $score >= 20 => ['level' => 'C1', 'description' => 'Advanced range: strong performance on this diagnostic; use a broader assessment to confirm.'],
        $score >= 15 => ['level' => 'B2', 'description' => 'Upper-intermediate range: you handled many complex language and reading items.'],
        $score >= 10 => ['level' => 'B1', 'description' => 'Intermediate range: you showed developing independence with everyday and academic English.'],
        $score >= 5 => ['level' => 'A2', 'description' => 'Elementary range: you showed understanding of common language and short texts.'],
        default => ['level' => 'A1', 'description' => 'Beginning range: start with common words, simple sentences, and short supported texts.']
    };
}
