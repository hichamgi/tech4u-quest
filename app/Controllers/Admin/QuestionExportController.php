<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Url;
use App\Models\Question;

final class QuestionExportController
{
    public function template(): void
    {
        Auth::requireAdmin(Url::to('login'));
        header('Content-Type:text/csv; charset=UTF-8');
        header('Content-Disposition:attachment; filename="questions-template.csv"');
        echo "\xEF\xBB\xBF";
        echo "category_id;question;type;difficulty;lesson;topic;explanation;exclusion_group;answer_1;correct_1;answer_2;correct_2;answer_3;correct_3;answer_4;correct_4;answer_5;correct_5;answer_6;correct_6;active\n";
    }

    public function export(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $model = new Question(Database::connection());
        header('Content-Type:text/csv; charset=UTF-8');
        header('Content-Disposition:attachment; filename="tech4u-questions.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");

        $header = ['id','module_id','category_id','question','type','difficulty','lesson','topic','explanation','exclusion_group','active'];
        for ($i = 1; $i <= 6; $i++) {
            $header[] = 'answer_' . $i;
            $header[] = 'correct_' . $i;
        }
        fputcsv($out, $header, ';', '"', '\\');

        foreach ($model->exportRows() as $question) {
            $row = [
                $question['id'],
                $question['module_id'],
                $question['category_id'],
                $question['question'],
                $question['type'],
                $question['difficulty'],
                $question['lesson'],
                $question['topic'],
                $question['explanation'],
                $question['exclusion_group'],
                $question['active'],
            ];
            $answers = $question['answers'] ?? [];
            for ($i = 0; $i < 6; $i++) {
                $row[] = $answers[$i]['answer'] ?? '';
                $row[] = $answers[$i]['is_correct'] ?? '';
            }
            fputcsv($out, $row, ';', '"', '\\');
        }
        fclose($out);
    }

    public function reference(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $model = new Question(Database::connection());
        header('Content-Type:text/csv; charset=UTF-8');
        header('Content-Disposition:attachment; filename="modules_categories.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['module_id','module','category_id','category','recommended_bank_size'], ';', '"', '\\');
        foreach ($model->referenceRows() as $row) {
            fputcsv($out, $row, ';', '"', '\\');
        }
        fclose($out);
    }
}
