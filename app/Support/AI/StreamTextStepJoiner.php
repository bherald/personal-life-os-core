<?php

namespace App\Support\AI;

final class StreamTextStepJoiner
{
    private int|string|null $currentStep = null;

    private string $pendingWhitespace = '';

    private bool $currentStepEmittedText = false;

    private bool $priorStepEmittedText = false;

    public function append(int|string $step, string $delta): ?string
    {
        if ($this->currentStep !== $step) {
            if ($this->currentStepEmittedText) {
                $this->priorStepEmittedText = true;
            }

            $this->currentStep = $step;
            $this->pendingWhitespace = '';
            $this->currentStepEmittedText = false;
        }

        if ($this->currentStepEmittedText) {
            return $delta;
        }

        $this->pendingWhitespace .= $delta;

        if (trim($this->pendingWhitespace) === '') {
            return null;
        }

        $output = ($this->priorStepEmittedText ? "\n\n" : '').$this->pendingWhitespace;
        $this->pendingWhitespace = '';
        $this->currentStepEmittedText = true;

        return $output;
    }
}
