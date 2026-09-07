<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class GameService
{
    public function __construct(private PDO $db) {}

    public function modulesForStudent(int $studentId, bool $includeInactive = false): array
    {
        $stmt=$this->db->prepare('SELECT m.id,m.title,m.description,m.icon,ms.question_count,ms.initial_lives,(SELECT COUNT(*) FROM questions q JOIN categories c2 ON c2.id=q.category_id WHERE c2.module_id=m.id AND q.active=1) AS bank_size,(SELECT MAX(a.score) FROM attempts a WHERE a.student_id=:student_best AND a.module_id=m.id) AS best_score,(SELECT a2.id FROM attempts a2 WHERE a2.student_id=:student_current AND a2.module_id=m.id AND a2.status="in_progress" ORDER BY a2.id DESC LIMIT 1) AS current_attempt_id,EXISTS(SELECT 1 FROM attempts ac WHERE ac.student_id=:student_completed AND ac.module_id=m.id AND ac.status="completed") AS completed,b.id AS badge_id,b.name AS badge_name,b.icon AS badge_icon,EXISTS(SELECT 1 FROM student_badges sb WHERE sb.student_id=:student_badge AND sb.badge_id=b.id) AS badge_obtained FROM modules m JOIN module_settings ms ON ms.module_id=m.id LEFT JOIN badges b ON b.module_id=m.id WHERE (m.active=1 OR CAST(:include_inactive AS INTEGER)=1) ORDER BY m.display_order,m.id');
        $stmt->execute([
            'student_best'=>$studentId,'student_current'=>$studentId,'student_completed'=>$studentId,'student_badge'=>$studentId,
            'include_inactive'=>$includeInactive?1:0,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function module(int $moduleId, bool $includeInactive = false): array
    {
        $stmt=$this->db->prepare('SELECT m.*,ms.question_count,ms.initial_lives,ms.badge_enabled,b.name AS badge_name,b.description AS badge_description,b.icon AS badge_icon FROM modules m JOIN module_settings ms ON ms.module_id=m.id LEFT JOIN badges b ON b.module_id=m.id WHERE m.id=:id AND (m.active=1 OR CAST(:include_inactive AS INTEGER)=1)');
        $stmt->execute(['id'=>$moduleId,'include_inactive'=>$includeInactive?1:0]);
        $module=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$module) throw new RuntimeException('Module introuvable.');

        $cats=$this->db->prepare('SELECT c.id,c.name,c.description,c.recommended_bank_size,COALESCE(mcs.question_count,1) AS draw_count,COUNT(CASE WHEN q.active=1 THEN q.id END) AS active_questions FROM categories c LEFT JOIN module_category_settings mcs ON mcs.category_id=c.id AND mcs.module_id=c.module_id LEFT JOIN questions q ON q.category_id=c.id WHERE c.module_id=:module_id AND c.active=1 GROUP BY c.id ORDER BY c.display_order,c.id');
        $cats->execute(['module_id'=>$moduleId]);
        $module['categories']=$cats->fetchAll(PDO::FETCH_ASSOC);
        return $module;
    }

    public function pathsForStudent(int $moduleId, int $studentId, bool $isDemo = false): array
    {
        $stmt=$this->db->prepare('SELECT p.*,
            EXISTS(SELECT 1 FROM attempts a WHERE a.student_id=:student_completed AND a.path_id=p.id AND a.status="completed") AS completed,
            (SELECT a2.id FROM attempts a2 WHERE a2.student_id=:student_current AND a2.path_id=p.id AND a2.status="in_progress" ORDER BY a2.id DESC LIMIT 1) AS current_attempt_id
            FROM module_paths p WHERE p.module_id=:module AND p.active=1 ORDER BY p.display_order,p.id');
        $stmt->execute(['student_completed'=>$studentId,'student_current'=>$studentId,'module'=>$moduleId]);
        $paths=$stmt->fetchAll(PDO::FETCH_ASSOC);

        $previousCompleted=true;
        foreach($paths as &$path){
            $completed=(int)$path['completed']===1;
            $path['unlocked']=$isDemo || (int)$path['display_order']===1 || $previousCompleted;
            $previousCompleted=$completed;
        }
        unset($path);
        return $paths;
    }

    public function startOrResume(int $studentId,int $moduleId,int $pathId,bool $includeInactive=false,bool $isDemo=false): int
    {
        $this->module($moduleId,$includeInactive);
        $path=$this->path($pathId,$moduleId);
        if(!$this->pathUnlocked($studentId,$path,$isDemo)) {
            throw new RuntimeException('Ce parcours est verrouillé. Termine le parcours précédent pour le débloquer.');
        }

        $stmt=$this->db->prepare('SELECT id FROM attempts WHERE student_id=:student AND module_id=:module AND path_id=:path AND status="in_progress" ORDER BY id DESC LIMIT 1');
        $stmt->execute(['student'=>$studentId,'module'=>$moduleId,'path'=>$pathId]);
        $existing=$stmt->fetchColumn();
        if($existing!==false) return (int)$existing;

        $lastStmt=$this->db->prepare('SELECT * FROM attempts WHERE student_id=:student AND module_id=:module AND path_id=:path ORDER BY id DESC LIMIT 1');
        $lastStmt->execute(['student'=>$studentId,'module'=>$moduleId,'path'=>$pathId]);
        $last=$lastStmt->fetch(PDO::FETCH_ASSOC)?:null;
        return $this->createAttempt($studentId,$moduleId,$path,$last,$includeInactive);
    }

    private function path(int $pathId,int $moduleId): array
    {
        $stmt=$this->db->prepare('SELECT * FROM module_paths WHERE id=:id AND module_id=:module AND active=1');
        $stmt->execute(['id'=>$pathId,'module'=>$moduleId]);
        $path=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$path) throw new RuntimeException('Parcours introuvable.');
        return $path;
    }

    private function pathUnlocked(int $studentId,array $path,bool $isDemo): bool
    {
        if($isDemo || (int)$path['display_order']===1) return true;
        $stmt=$this->db->prepare('SELECT id FROM module_paths WHERE module_id=:module AND active=1 AND display_order<:ord ORDER BY display_order DESC LIMIT 1');
        $stmt->execute(['module'=>$path['module_id'],'ord'=>$path['display_order']]);
        $previousId=$stmt->fetchColumn();
        if($previousId===false) return true;
        $done=$this->db->prepare('SELECT 1 FROM attempts WHERE student_id=:student AND path_id=:path AND status="completed" LIMIT 1');
        $done->execute(['student'=>$studentId,'path'=>(int)$previousId]);
        return $done->fetchColumn()!==false;
    }

    private function createAttempt(int $studentId,int $moduleId,array $path,?array $previous,bool $includeInactive=false): int
    {
        $module=$this->module($moduleId,$includeInactive);
        $questionCount=(int)$path['question_count'];
        $initialLives=(int)$module['initial_lives'];
        $attemptNumber=$previous?((int)$previous['attempt_number']+1):1;

        if($previous&&(string)$previous['status']==='game_over'){
            $questions=$this->buildRetryPath($moduleId,$path,$previous,$questionCount);
        }else{
            $questions=$this->buildFreshPath($moduleId,$path,$questionCount);
        }

        if(count($questions)!==$questionCount) throw new RuntimeException('La banque de questions ne permet pas de construire ce parcours.');

        $this->db->beginTransaction();
        try{
            $insert=$this->db->prepare('INSERT INTO attempts(student_id,module_id,path_id,attempt_number,total_questions,current_position,lives,score,status) VALUES(:student,:module,:path,:attempt_number,:total,1,:lives,0,"in_progress")');
            $insert->execute(['student'=>$studentId,'module'=>$moduleId,'path'=>$path['id'],'attempt_number'=>$attemptNumber,'total'=>$questionCount,'lives'=>$initialLives]);
            $attemptId=(int)$this->db->lastInsertId();
            $iq=$this->db->prepare('INSERT INTO attempt_questions(attempt_id,position,question_id) VALUES(:attempt,:position,:question)');
            foreach($questions as $position=>$questionId) $iq->execute(['attempt'=>$attemptId,'position'=>$position,'question'=>$questionId]);
            $this->db->commit();
            return $attemptId;
        }catch(\Throwable $e){
            if($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function buildRetryPath(int $moduleId,array $path,array $previous,int $questionCount): array
    {
        $oldStmt=$this->db->prepare('SELECT aq.position,aq.question_id,q.category_id,q.exclusion_group FROM attempt_questions aq JOIN questions q ON q.id=aq.question_id WHERE aq.attempt_id=:attempt ORDER BY aq.position');
        $oldStmt->execute(['attempt'=>$previous['id']]);
        $oldPath=$oldStmt->fetchAll(PDO::FETCH_ASSOC);
        if(count($oldPath)!==$questionCount) return $this->buildFreshPath($moduleId,$path,$questionCount);

        $encountered=min((int)$previous['current_position'],$questionCount);
        $newPath=[];$usedIds=[];$usedGroups=[];
        foreach($oldPath as $old){
            $pos=(int)$old['position'];
            if($pos<=$encountered) continue;
            $qid=(int)$old['question_id'];
            $newPath[$pos]=$qid;$usedIds[]=$qid;
            $g=trim((string)($old['exclusion_group']??''));if($g!=='')$usedGroups[$g]=true;
        }
        foreach($oldPath as $old){
            $pos=(int)$old['position'];if($pos>$encountered) continue;
            $oldId=(int)$old['question_id'];$categoryId=(int)$old['category_id'];
            $qid=$this->pickQuestion($categoryId,$path,array_values(array_unique(array_merge($usedIds,[$oldId]))),array_keys($usedGroups),false);
            if($qid===null) $qid=$this->pickQuestion($categoryId,$path,$usedIds,array_keys($usedGroups),true);
            $newPath[$pos]=$qid;$usedIds[]=$qid;
            $meta=$this->questionMeta($qid);$g=trim((string)($meta['exclusion_group']??''));if($g!=='')$usedGroups[$g]=true;
        }
        ksort($newPath);
        return $newPath;
    }

    private function buildFreshPath(int $moduleId,array $path,int $questionCount): array
    {
        $quota=$this->categoryAllocation($moduleId,$questionCount);
        $questionIds=[];$usedGroups=[];
        foreach($quota as $categoryId=>$needed){
            for($i=0;$i<$needed;$i++){
                $qid=$this->pickQuestion((int)$categoryId,$path,$questionIds,array_keys($usedGroups),true);
                $questionIds[]=$qid;
                $meta=$this->questionMeta($qid);
                $g=trim((string)($meta['exclusion_group']??''));if($g!=='')$usedGroups[$g]=true;
            }
        }
        shuffle($questionIds);
        $result=[];foreach($questionIds as $i=>$qid)$result[$i+1]=$qid;
        return $result;
    }

    private function categoryAllocation(int $moduleId,int $questionCount): array
    {
        $stmt=$this->db->prepare('SELECT c.id,COALESCE(NULLIF(mcs.question_count,0),1) AS weight FROM categories c LEFT JOIN module_category_settings mcs ON mcs.category_id=c.id AND mcs.module_id=c.module_id WHERE c.module_id=:module AND c.active=1 ORDER BY c.display_order,c.id');
        $stmt->execute(['module'=>$moduleId]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(!$rows) throw new RuntimeException('Aucune catégorie active pour ce module.');
        $totalWeight=array_sum(array_map(static fn(array $r):int=>(int)$r['weight'],$rows));
        $allocation=[];$remainders=[];$assigned=0;
        foreach($rows as $row){
            $raw=$questionCount*((int)$row['weight']/$totalWeight);
            $base=(int)floor($raw);
            $allocation[(int)$row['id']]=$base;
            $remainders[(int)$row['id']]=$raw-$base;
            $assigned+=$base;
        }
        arsort($remainders);
        foreach(array_keys($remainders) as $categoryId){
            if($assigned>=$questionCount) break;
            $allocation[$categoryId]++;$assigned++;
        }
        return array_filter($allocation,static fn(int $n):bool=>$n>0);
    }

    private function pickQuestion(int $categoryId,array $path,array $exclude=[],array $excludedGroups=[],bool $required=true): ?int
    {
        $stmt=$this->db->prepare('SELECT id,difficulty,exclusion_group FROM questions WHERE category_id=:category AND active=1 ORDER BY difficulty,id');
        $stmt->execute(['category'=>$categoryId]);
        $all=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(!$all){if(!$required)return null;throw new RuntimeException("Aucune question active dans la catégorie {$categoryId}.");}

        $poolSize=max(1,(int)ceil(count($all)*((int)$path['pool_percent']/100)));
        $pool=array_slice($all,0,$poolSize);
        $excludeIds=array_flip(array_map('intval',$exclude));
        $excludeGroups=array_flip(array_map('strval',$excludedGroups));
        $eligible=array_values(array_filter($pool,static function(array $q) use($excludeIds,$excludeGroups):bool{
            if(isset($excludeIds[(int)$q['id']])) return false;
            $g=trim((string)($q['exclusion_group']??''));
            return $g==='' || !isset($excludeGroups[$g]);
        }));
        if(!$eligible){if(!$required)return null;throw new RuntimeException("Le pool du parcours est insuffisant dans la catégorie {$categoryId}. Augmente la banque ou ajuste les exclusions.");}

        $weights=$this->difficultyWeights((string)$path['code']);
        $weighted=[];$total=0;
        foreach($eligible as $q){$w=max(1,$weights[(int)$q['difficulty']]??1);$total+=$w;$weighted[]=[$q,$total];}
        $pick=random_int(1,$total);
        foreach($weighted as [$q,$limit]) if($pick<=$limit) return (int)$q['id'];
        return (int)$eligible[array_key_last($eligible)]['id'];
    }

    private function difficultyWeights(string $code): array
    {
        return match($code){
            'discovery' => [1=>70,2=>30,3=>1,4=>1,5=>1],
            'training'  => [1=>25,2=>20,3=>45,4=>10,5=>1],
            'mastery'   => [1=>10,2=>15,3=>50,4=>20,5=>5],
            'expert'    => [1=>5,2=>10,3=>40,4=>25,5=>20],
            default     => [1=>20,2=>20,3=>20,4=>20,5=>20],
        };
    }

    private function questionMeta(int $questionId): array
    {
        $s=$this->db->prepare('SELECT id,category_id,exclusion_group FROM questions WHERE id=:id');$s->execute(['id'=>$questionId]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Question introuvable.');return $r;
    }

    public function attempt(int $attemptId,int $studentId): array
    {
        $stmt=$this->db->prepare('SELECT a.*,m.title AS module_title,m.icon AS module_icon,p.code AS path_code,p.name AS path_name,p.icon AS path_icon,b.name AS badge_name,b.icon AS badge_icon FROM attempts a JOIN modules m ON m.id=a.module_id LEFT JOIN module_paths p ON p.id=a.path_id LEFT JOIN badges b ON b.module_id=m.id WHERE a.id=:id AND a.student_id=:student');
        $stmt->execute(['id'=>$attemptId,'student'=>$studentId]);
        $attempt=$stmt->fetch(PDO::FETCH_ASSOC);if(!$attempt)throw new RuntimeException('Tentative introuvable.');return $attempt;
    }

    public function currentQuestion(int $attemptId,int $studentId): array
    {
        $attempt=$this->attempt($attemptId,$studentId);if((string)$attempt['status']!=='in_progress')throw new RuntimeException('Cette tentative est terminée.');
        $stmt=$this->db->prepare('SELECT aq.id AS attempt_question_id,aq.position,aq.wrong_answers,q.id,q.question,q.type,q.difficulty,q.explanation,q.lesson,q.topic,c.name AS category_name FROM attempt_questions aq JOIN questions q ON q.id=aq.question_id JOIN categories c ON c.id=q.category_id WHERE aq.attempt_id=:attempt AND aq.position=:position');
        $stmt->execute(['attempt'=>$attemptId,'position'=>$attempt['current_position']]);$question=$stmt->fetch(PDO::FETCH_ASSOC);if(!$question)throw new RuntimeException('Question courante introuvable.');
        $ans=$this->db->prepare('SELECT id,answer,display_order FROM question_answers WHERE question_id=:question ORDER BY RANDOM()');$ans->execute(['question'=>$question['id']]);$question['answers']=$ans->fetchAll(PDO::FETCH_ASSOC);$question['attempt']=$attempt;return $question;
    }

    public function submit(int $attemptId,int $studentId,array $input): array
    {
        $question=$this->currentQuestion($attemptId,$studentId);$attempt=$question['attempt'];$type=(string)$question['type'];
        $answerStmt=$this->db->prepare('SELECT id,answer,is_correct FROM question_answers WHERE question_id=:question ORDER BY display_order,id');$answerStmt->execute(['question'=>$question['id']]);$answers=$answerStmt->fetchAll(PDO::FETCH_ASSOC);$isCorrect=false;$answerId=null;$shortAnswer=null;
        if($type==='qcm'||$type==='true_false'){$answerId=(int)($input['answer_id']??0);foreach($answers as $a)if((int)$a['id']===$answerId){$isCorrect=(int)$a['is_correct']===1;break;}if($answerId<1)throw new RuntimeException('Choisis une réponse.');}
        elseif($type==='multiple'){$selected=array_values(array_unique(array_map('intval',(array)($input['answer_ids']??[]))));sort($selected);$correctIds=[];$allowedIds=[];foreach($answers as $a){$allowedIds[]=(int)$a['id'];if((int)$a['is_correct']===1)$correctIds[]=(int)$a['id'];}sort($correctIds);foreach($selected as $sid)if(!in_array($sid,$allowedIds,true))throw new RuntimeException('Réponse invalide.');if(!$selected)throw new RuntimeException('Choisis au moins une réponse.');$isCorrect=$selected===$correctIds;$shortAnswer=json_encode($selected,JSON_THROW_ON_ERROR);}
        elseif($type==='short'){$shortAnswer=trim((string)($input['short_answer']??''));if($shortAnswer==='')throw new RuntimeException('Saisis une réponse.');$normalized=mb_strtolower($shortAnswer);foreach($answers as $a)if((int)$a['is_correct']===1&&mb_strtolower(trim((string)$a['answer']))===$normalized){$isCorrect=true;break;}}

        $this->db->beginTransaction();
        try{
            $log=$this->db->prepare('INSERT INTO attempt_answers(attempt_id,attempt_question_id,answer_id,short_answer,is_correct) VALUES(:attempt,:aq,:answer_id,:short_answer,:correct)');
            $log->execute(['attempt'=>$attemptId,'aq'=>$question['attempt_question_id'],'answer_id'=>$answerId?:null,'short_answer'=>$shortAnswer,'correct'=>$isCorrect?1:0]);
            if($isCorrect){
                $this->db->prepare('UPDATE attempt_questions SET answered=1,completed=1 WHERE id=:id')->execute(['id'=>$question['attempt_question_id']]);
                $newScore=(int)$attempt['score']+1;$position=(int)$attempt['current_position'];$total=(int)$attempt['total_questions'];
                if($position>=$total){
                    $this->db->prepare('UPDATE attempts SET score=:score,status="completed",finished_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['score'=>$newScore,'id'=>$attemptId]);
                    if((string)($attempt['path_code']??'')==='expert') $this->awardBadge($studentId,(int)$attempt['module_id'],$attemptId);
                    $this->db->commit();return['correct'=>true,'status'=>'completed','attempt_id'=>$attemptId];
                }
                $this->db->prepare('UPDATE attempts SET score=:score,current_position=current_position+1 WHERE id=:id')->execute(['score'=>$newScore,'id'=>$attemptId]);
                $this->db->commit();return['correct'=>true,'status'=>'in_progress','attempt_id'=>$attemptId];
            }
            $newLives=max(0,(int)$attempt['lives']-1);
            $this->db->prepare('UPDATE attempt_questions SET answered=1,wrong_answers=wrong_answers+1 WHERE id=:id')->execute(['id'=>$question['attempt_question_id']]);
            if($newLives===0){$this->db->prepare('UPDATE attempts SET lives=0,status="game_over",finished_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id'=>$attemptId]);$this->db->commit();return['correct'=>false,'status'=>'game_over','attempt_id'=>$attemptId];}
            $this->db->prepare('UPDATE attempts SET lives=:lives WHERE id=:id')->execute(['lives'=>$newLives,'id'=>$attemptId]);$this->db->commit();return['correct'=>false,'status'=>'in_progress','attempt_id'=>$attemptId,'lives'=>$newLives];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function awardBadge(int $studentId,int $moduleId,int $attemptId): void
    {
        $stmt=$this->db->prepare('SELECT b.id FROM badges b JOIN module_settings ms ON ms.module_id=b.module_id WHERE b.module_id=:module AND ms.badge_enabled=1 LIMIT 1');$stmt->execute(['module'=>$moduleId]);$badgeId=$stmt->fetchColumn();if($badgeId===false)return;$insert=$this->db->prepare('INSERT OR IGNORE INTO student_badges(student_id,badge_id,attempt_id) VALUES(:student,:badge,:attempt)');$insert->execute(['student'=>$studentId,'badge'=>(int)$badgeId,'attempt'=>$attemptId]);
    }
}
