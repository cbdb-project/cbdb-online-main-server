<?php

namespace Tests\Feature;

use Tests\TestCase;

class BasicInformationEditButtonStateTest extends TestCase
{
    protected $bladeTemplatePath;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set the path to the Blade template
        $this->bladeTemplatePath = resource_path('views/biogmains/basicinformation/edit.blade.php');
    }

    public function testBladeTemplateExists()
    {
        $this->assertFileExists(
            $this->bladeTemplatePath,
            'Blade template file should exist at resources/views/biogmains/basicinformation/edit.blade.php'
        );
    }

    public function testFormHasCorrectId()
    {
        $content = file_get_contents($this->bladeTemplatePath);
        
        $this->assertStringContainsString(
            'id="basic-info-form"',
            $content,
            'Template should contain form with id="basic-info-form"'
        );
    }

    public function testSubmitButtonHasCorrectId()
    {
        $content = file_get_contents($this->bladeTemplatePath);
        
        $this->assertStringContainsString(
            'id="basic-info-submit"',
            $content,
            'Template should contain submit button with id="basic-info-submit"'
        );
    }

    public function testJavaScriptVariablesAreDeclared()
    {
        $content = file_get_contents($this->bladeTemplatePath);
        
        // Verify the JavaScript variables are declared
        $this->assertStringContainsString(
            'var $basicInfoForm = $(\'#basic-info-form\');',
            $content,
            'Template should declare $basicInfoForm variable'
        );
        
        $this->assertStringContainsString(
            'var $submitButton = $(\'#basic-info-submit\');',
            $content,
            'Template should declare $submitButton variable'
        );
        
        $this->assertStringContainsString(
            'var pristineSnapshot = $basicInfoForm.serialize();',
            $content,
            'Template should declare pristineSnapshot variable'
        );
    }

    public function testEvaluateFormDirtyFunctionExists()
    {
        $content = file_get_contents($this->bladeTemplatePath);
        
        // Verify the evaluateFormDirty function exists
        $this->assertStringContainsString(
            'function evaluateFormDirty()',
            $content,
            'Template should contain evaluateFormDirty() function'
        );
        
        // Verify the function logic
        $this->assertStringContainsString(
            'var isDirty = $basicInfoForm.serialize() !== pristineSnapshot;',
            $content,
            'evaluateFormDirty() should check if form is dirty'
        );
        
        $this->assertStringContainsString(
            '$submitButton.prop(\'disabled\', !isDirty);',
            $content,
            'evaluateFormDirty() should enable/disable submit button based on dirty state'
        );
    }

    public function testFormChangeEventListenersAreAttached()
    {
        $content = file_get_contents($this->bladeTemplatePath);
        
        // Verify event listeners are attached
        $this->assertStringContainsString(
            '$basicInfoForm.on(\'change input\', \'input, select, textarea\'',
            $content,
            'Template should attach change/input event listeners to form fields'
        );
        
        $this->assertStringContainsString(
            'evaluateFormDirty();',
            $content,
            'Event listeners should call evaluateFormDirty()'
        );
    }

    public function testInitialButtonStateIsEvaluated()
    {
        $content = file_get_contents($this->bladeTemplatePath);
        
        // Verify that evaluateFormDirty() is called on page load
        // This ensures the button starts in the correct disabled state
        $this->assertStringContainsString(
            'evaluateFormDirty();',
            $content,
            'Template should call evaluateFormDirty() on page load'
        );
        
        // Verify the pristine snapshot is captured
        $this->assertStringContainsString(
            'var pristineSnapshot = $basicInfoForm.serialize();',
            $content,
            'Template should capture pristine snapshot of form state'
        );
    }

    public function testFormSubmitHandlerIsPresent()
    {
        $content = file_get_contents($this->bladeTemplatePath);
        
        // Verify the form submit handler that updates pristine snapshot
        $this->assertStringContainsString(
            '$basicInfoForm.on(\'submit\', function ()',
            $content,
            'Template should have form submit handler'
        );
        
        $this->assertStringContainsString(
            'pristineSnapshot = $basicInfoForm.serialize();',
            $content,
            'Submit handler should update pristine snapshot'
        );
        
        $this->assertStringContainsString(
            '$submitButton.prop(\'disabled\', true);',
            $content,
            'Submit handler should disable button on submit'
        );
    }

    public function testJavaScriptCodeStructure()
    {
        $content = file_get_contents($this->bladeTemplatePath);
        
        // Verify the code is wrapped in document ready
        $this->assertStringContainsString(
            '$(document).ready(function ()',
            $content,
            'JavaScript code should be wrapped in document ready'
        );
        
        // Verify jQuery is being used
        $this->assertStringContainsString(
            '$(',
            $content,
            'Template should use jQuery'
        );
    }

    public function testConditionalSubmitButtonRendering()
    {
        $content = file_get_contents($this->bladeTemplatePath);
        
        // Verify that submit button is conditionally rendered based on user active status
        $this->assertStringContainsString(
            '@if(Auth::user()->is_active == 1)',
            $content,
            'Template should conditionally render submit button for active users only'
        );
    }
}