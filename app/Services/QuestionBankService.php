<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class QuestionBankService
{
    public function __construct(private PDO $db) {}

    public function save(array $data, ?int $questionId = null): int
    {
        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $question = trim((string)($data['question'] ?? ''));
        $type = (string)($data['type'] ?? 'qcm');
        $difficulty = filter_var($data['difficulty'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>5]]);
        $lesson = trim((string)($data['lesson'] ?? ''));
        $topic = trim((string)($data['topic'] ?? ''));
        $explanation = trim((string)($data['explanation'] ?? ''));
        $exclusionGroup = trim((string)($data['exclusion_group'] ?? ''));
        if ($exclusionGroup !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,50}$/', $exclusionGroup)) {
            throw new RuntimeException('Le groupe d’exclusion doit contenir uniquement lettres, chiffres, tiret, point ou underscore (50 caractères max).');
        }
        $active = !empty($data['active']) ? 1 : 0;
        $answers = $data['answers'] ?? [];

        if ($categoryId === false || $question === '' || $difficulty === false || !in_array($type,['qcm','true_false','multiple','short'],true)) throw new RuntimeException('Question invalide.');
        if (!is_array($answers)) throw new RuntimeException('Réponses invalides.');

        $category = $this->db->prepare('SELECT module_id FROM categories WHERE id=:id AND active=1');
        $category->execute(['id'=>$categoryId]);
        if (!$category->fetchColumn()) throw new RuntimeException('Catégorie inexistante ou inactive.');

        $clean=[];
        foreach($answers as $a){if(!is_array($a))continue;$text=trim((string)($a['answer']??''));if($text==='')continue;$clean[]=['answer'=>$text,'is_correct'=>!empty($a['is_correct'])?1:0];}
        $correct=array_sum(array_column($clean,'is_correct'));
        if($type==='qcm'&&(count($clean)<2||$correct!==1)) throw new RuntimeException('Un QCM doit avoir au moins 2 réponses et exactement 1 bonne réponse.');
        if($type==='multiple'&&(count($clean)<2||$correct<1)) throw new RuntimeException('Une question multiple doit avoir au moins 2 réponses et au moins 1 bonne réponse.');
        if($type==='true_false'&&(count($clean)!==2||$correct!==1)) throw new RuntimeException('Vrai/Faux doit avoir exactement 2 réponses et 1 bonne réponse.');
        if($type==='short'&&(count($clean)<1||$correct<1)) throw new RuntimeException('Une réponse courte doit avoir au moins une réponse acceptée.');

        $dup=$this->db->prepare('SELECT id FROM questions WHERE lower(trim(question))=lower(trim(:q)) AND id<>:id LIMIT 1');
        $dup->execute(['q'=>$question,'id'=>$questionId??0]); if($dup->fetchColumn()) throw new RuntimeException('Une question identique existe déjà.');

        $this->db->beginTransaction();
        try {
            if($questionId){
                $stmt=$this->db->prepare('UPDATE questions SET category_id=:c,question=:q,type=:t,difficulty=:d,lesson=:l,topic=:p,explanation=:e,exclusion_group=:g,active=:a,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
                $stmt->execute(['c'=>$categoryId,'q'=>$question,'t'=>$type,'d'=>$difficulty,'l'=>$lesson?:null,'p'=>$topic?:null,'e'=>$explanation?:null,'g'=>$exclusionGroup?:null,'a'=>$active,'id'=>$questionId]);
                $this->db->prepare('DELETE FROM question_answers WHERE question_id=:id')->execute(['id'=>$questionId]); $id=$questionId;
            }else{
                $stmt=$this->db->prepare('INSERT INTO questions(category_id,question,type,difficulty,lesson,topic,explanation,exclusion_group,active) VALUES(:c,:q,:t,:d,:l,:p,:e,:g,:a)');
                $stmt->execute(['c'=>$categoryId,'q'=>$question,'t'=>$type,'d'=>$difficulty,'l'=>$lesson?:null,'p'=>$topic?:null,'e'=>$explanation?:null,'g'=>$exclusionGroup?:null,'a'=>$active]); $id=(int)$this->db->lastInsertId();
            }
            $ins=$this->db->prepare('INSERT INTO question_answers(question_id,answer,is_correct,display_order) VALUES(:q,:a,:c,:o)');
            foreach($clean as $i=>$a)$ins->execute(['q'=>$id,'a'=>$a['answer'],'c'=>$a['is_correct'],'o'=>$i+1]);
            $this->db->commit(); return $id;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function duplicate(int $id): int
    {
        $q=$this->db->prepare('SELECT * FROM questions WHERE id=:id');$q->execute(['id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Question introuvable.');
        $a=$this->db->prepare('SELECT answer,is_correct FROM question_answers WHERE question_id=:id ORDER BY display_order,id');$a->execute(['id'=>$id]);$answers=$a->fetchAll(PDO::FETCH_ASSOC);
        return $this->save(['category_id'=>$row['category_id'],'question'=>$row['question'].' (copie)','type'=>$row['type'],'difficulty'=>$row['difficulty'],'lesson'=>$row['lesson'],'topic'=>$row['topic'],'explanation'=>$row['explanation'],'exclusion_group'=>$row['exclusion_group']??'','active'=>0,'answers'=>$answers]);
    }

    public function toggle(int $id): void
    {
        $stmt=$this->db->prepare('UPDATE questions SET active=CASE active WHEN 1 THEN 0 ELSE 1 END, updated_at=CURRENT_TIMESTAMP WHERE id=:id');$stmt->execute(['id'=>$id]);
    }
}
