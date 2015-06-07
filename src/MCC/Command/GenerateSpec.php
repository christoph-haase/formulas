<?php
namespace MCC\Command;

class GenerateSpec extends Base
{

  protected function configure()
  {
    $this
      ->setName('model:to-spec')
      ->setDescription('Generate a .spec description');
    parent::configure();
  }

  protected function perform()
  {
    if ($this->pt_model == NULL)
      return;
    $model = $this->pt_model;
    $quantity = count($model->net->page->place) +
      count($model->net->page->transition);
    $this->progress->setRedrawFrequency(max(1, $quantity / 100));
    $this->progress->start($this->console_output, $quantity);
    $file  = dirname(realpath($this->pt_file)) . '/model.spec';
    $fp = fopen($file, 'w');
    fwrite($fp, "vars\n");
    fwrite($fp, "\t");
    $initmarking = array();
    foreach ($model->net->page->place as $place) {
      $id = (string) $place->attributes()['id'];
      fwrite($fp, "${id} ");
      $initial = (string) $place->initialMarking->text;
      if (($initial == NULL) || ($initial == ""))
        $initial = 0;
      $initmarking[] = "${id} = ${initial}";
      $this->progress->advance();
    }
    fwrite($fp, "\n\n");
    fwrite($fp, "rules\n");
    $pre  = array ();
    $post = array ();
    foreach ($model->net->page->arc as $arc) {
      $source = (string) $arc->attributes()['source'];
      $target = (string) $arc->attributes()['target'];
      if (! isset ($pre [$target]))
        $pre  [$target] = array ();
      if (! isset ($post [$source]))
        $post [$source] = array ();
      $pre  [$target] [] = $arc;
      $post [$source] [] = $arc;
    }
    foreach ($model->net->page->transition as $transition) {
      $id             = (string) $transition->attributes()['id'];
      $name           = (string) $transition->name->text;
      $preconditions  = array();
      $postconditions = array();
      foreach ($pre [$id] as $arc) {
        $source = (string) $arc->attributes()['source'];
        $value  = (string) $arc->inscription->text;
        if (($value == NULL) || ($value == ""))
          $value = 1;
        $preconditions  [] = "\t${source} >= ${value}";
        $postconditions [] = "\t\t${source}' = ${source} - ${value}";
      }
      if (! isset ($post [$id])) {
        $post [$id] = array ();
      }
      foreach ($post [$id] as $arc) {
        $target = (string) $arc->attributes()['target'];
        $value  = (string) $arc->inscription->text;
        if (($value == NULL) || ($value == ""))
          $value = 1;
        $postconditions[] = "\t\t${target}' = ${target} + ${value}";
      }
      fwrite($fp, "\t# Transition ${id}: ${name}\n");
      fwrite($fp, implode(",\n", $preconditions));
      fwrite($fp, " ->\n");
      fwrite($fp, implode(",\n", $postconditions));
      fwrite($fp, ";\n \n");
      $this->progress->advance();
    }
    fwrite($fp, "init\n\t");
    fwrite($fp, implode(", ", $initmarking));
    fwrite($fp, "\n\n");
    fclose($fp);
    $this->progress->finish();
  }
}