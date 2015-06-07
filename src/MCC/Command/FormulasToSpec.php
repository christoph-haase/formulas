<?php
namespace MCC\Command;

use \Symfony\Component\Console\Input\InputInterface;
use \Symfony\Component\Console\Output\OutputInterface;
use \Symfony\Component\Console\Input\InputOption;
use \MCC\Command\Base;
use \MCC\Formula\EquivalentElements;


// Requires boolstuff package
class FormulasToSpec extends Base
{

  protected function configure()
  {
    $this
      ->setName('formula:to-spec')
      ->setDescription('Convert formulas to .spec target')
      ->addOption('output', null,
        InputOption::VALUE_REQUIRED,
        'File name for formulas output', 'formulas')
        ;
    parent::configure();
  }

  private $pt_path   = null;
  private $pt_input  = null;
  private $pt_output = null;
  private $pipes     = null;

  protected function pre_perform(InputInterface $input, OutputInterface $output)
  {
    $this->pt_path   = dirname($this->pt_file);
    $output          = $this->pt_path . "/" . $input->getOption('output');
    $this->pt_input  = "${output}.xml";
    $this->pt_output = "${output}.spec";
  }

  protected function perform()
  {
    if ($this->pt_model != null)
    {
      $error_file = tempnam ($this->pt_path, "sympy-err");
      $descriptorspec = array(
         0 => array("pipe", "r"),
         1 => array("pipe", "w"),
         2 => array("file", $error_file, "a")
       );
      $this->pipes = array ();
      $process = proc_open('python', $descriptorspec, $this->pipes, $this->pt_path, array ());
      if (is_resource($process))
      {
        stream_set_blocking ($this->pipes [0], 0);
        stream_set_blocking ($this->pipes [1], 0);
        $preamble = <<<EOS
from sympy.core           import symbols
from sympy.logic.boolalg  import to_dnf

EOS;
        fwrite ($this->pipes [0], $preamble);
        flush  ($this->pipes [0]);
        $output = stream_get_contents ($this->pipes[1]);
        $this->convert(
          $this->pt_input,
          $this->pt_output
        );
        fclose ($this->pipes [0]);
        fclose ($this->pipes [1]);
        if (proc_close($process) != 0)
        {
          $this->console_output->writeln("<error>Error: python errored</error>");
          $reason = file_get_contents ($error_file);
          $this->console_output->writeln("<error>${reason}</error>");
        }
        else
          unlink ($error_file);
      }
      else
        $this->console_output->writeln("<error>Error: unable to run python</error>");
    }
  }

  private function convert ($input, $output)
  {
    if (file_exists($output))
      unlink($output);
    if (! file_exists($input))
    {
      $this->console_output->writeln(
        "<error>Formula file {$input} not found.</error>"
      );
      return;
    }
    $xml = $this->load_xml(file_get_contents($input));
    $quantity = count($xml->children());
    $this->progress->setRedrawFrequency(max(1, $quantity / 100));
    $this->progress->start($this->console_output, $quantity);
    // Pre-compute arcs:
    $pre  = array ();
    foreach ($this->pt_model->net->page->arc as $arc)
    {
      $source = (string) $arc->attributes()['source'];
      $target = (string) $arc->attributes()['target'];
      if (! array_key_exists ($target, $pre ))
        $pre  [$target] = array ();
      $pre  [$target] [] = $arc;
    }
    $i = 0;
    foreach ($this->pt_model->net->page->place as $place)
      $i++;
    $padding   = log10 ($i)+3;
    $result = array();
    foreach ($xml->property as $property)
    {
      try
      {
        $variables = array ();
        $result[] = $this->translate_property($property, $pre, $padding, $variables);
      }
      catch (\Exception $e) {}
      $this->progress->advance();
    }
    $this->progress->finish();
    $result = implode("\n", $result);
    file_put_contents($output, $result . "\n");
  }

  private function translate_property($property, &$pre, $padding, &$variables)
  {
    $result      = null;
    $id          = (string) $property->id;
    $description = (string) $property->description;
    $formula     = $this->translate_formula($property->formula->children()[0], $pre, $padding, $variables);
    $output = array ();
    $search  = array ();
    $replace = array ();
    foreach ($variables as $key => $value)
    {
      fwrite ($this->pipes [0], "${value} = symbols (\"${value}\")\n");
      $search  [] = $value;
      $replace [] = $key;
    }
    fwrite ($this->pipes [0], "print (to_dnf (${formula}))\n");
    $output = stream_get_contents ($this->pipes[1]);
    foreach ($variables as $key => $value)
    {
      $search  [] = $value;
      $replace [] = $key;
    }
    $formula = str_replace ($search, $replace, $formula);
    $formula = str_replace ("&", ",", $formula);
    $formula = str_replace ("|", "\n\t", $formula);
    $formula = str_replace (array ("(", ")"), "", $formula);
    $result = <<<EOS
\t# ID: Property {$id}
\t# "{$description}"
\t{$formula}
EOS;
    return $result;
  }

  private function translate_formula($formula, &$pre, $padding, &$variables)
  {
    $result = null;
    switch ((string) $formula->getName())
    {
    case 'exists-path':
      $sub    = $formula->children()[0];
      $result = $this->translate_formula($sub, $pre, $padding, $variables);
      break;
    case 'finally':
      $sub    = $formula->children()[0];
      $result = $this->translate_formula($sub, $pre, $padding, $variables);
      break;
    case 'is-fireable':
      $transition = (string) $formula->transition;
      $targetdisjunction = array();
      foreach ($formula->transition as $transition)
      {
        $id = (string) $transition;
        $targetconjunction = array();
        foreach ($pre [$id] as $arc)
        {
          $source = (string) $arc->attributes()['source'];
          $value  = (string) $arc->inscription->text;
          if (($value == NULL) || ($value == ""))
            $value = 1;
          $expression = "${source}>=${value}";
          if (! isset ($variables [$expression]))
          {
            $count = count ($variables)+1;
            $var   = "v" . str_pad ($count, $padding, "0", STR_PAD_LEFT);
            $variables [$expression] = $var;
          }
          $targetconjunction [] = $variables [$expression];
        }
        $targetdisjunction[] = implode("&", $targetconjunction);
      }
      $result = "(" . implode("|", $targetdisjunction) . ")";
      break;
    case 'conjunction':
      $res = array();
      foreach ($formula->children() as $sub)
        $res [] = $this->translate_formula ($sub, $pre, $padding, $variables);
      $result = "(" . implode("&", $res) . ")";
      break;
    case 'disjunction':
      $res = array();
      foreach ($formula->children() as $sub)
        $res [] = $this->translate_formula ($sub, $pre, $padding, $variables);
      $result = "(" . implode("|", $res) . ")";
      break;
    case 'integer-le':
      $res = array();
      foreach ($formula->children() as $sub)
        $res[] = $this->translate_formula($sub, $pre, $padding, $variables);
      if (count($res) != 2)
      {
        $this->console_output->writeln("<warning>Error: no support for nary le </warning>");
        throw(new \Exception("unsupported subformula"));
      }
      if (is_numeric($res[0]) && !is_numeric($res[1]))
      {
        $expression = "${res[1]}>=${res[0]}";
        if (! isset ($variables [$expression]))
        {
          $count = count ($variables)+1;
          $var   = "v" . str_pad ($count, $padding, "0", STR_PAD_LEFT);
          $variables [$expression] = $var;
        }
        $result = $variables [$expression];
      }
      else
      {
        $this->console_output->writeln("<warning>Error: no support for less-than constraints place<=*</warning>");
        throw (new \Exception ("unsupported subformula"));
      }
      break;
    case 'integer-constant':
      $result = (string) $formula;
      break;
    case 'tokens-count':
      $res = array();
      foreach ($formula->place as $place)
      {
        $res[] = (string) $place;
      }
      if (count($res) != 1)
      {
        $this->console_output->writeln("<warning>Error: no support for comparison between places</warning>");
        throw(new \Exception("unsupported subformula"));
      }
      $result = $res[0];
      break;
    default:
      $this->console_output->writeln("<warning>Error: unknown node {$formula->getName()}</warning>");
      throw(new \Exception("unsupported subformula"));
    }
    return $result;
  }

}
