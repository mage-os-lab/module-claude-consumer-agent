<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Controller\Request;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Data\Form\FormKey\Validator;
use MageOS\AiShoppingAssistant\Controller\Request\FormKeyGuard;
use PHPUnit\Framework\TestCase;

final class FormKeyGuardTest extends TestCase
{
    public function testValidWhenFormKeyValidatorAcceptsTheParam(): void
    {
        $validator = $this->createMock(Validator::class);
        $validator->method('validate')->willReturn(true);
        $formKey = $this->createMock(FormKey::class);
        $request = $this->createMock(Http::class);

        $guard = new FormKeyGuard($validator, $formKey);
        $this->assertTrue($guard->isValid($request));
    }

    public function testValidWhenHeaderMatchesTheSessionFormKey(): void
    {
        $validator = $this->createMock(Validator::class);
        $validator->method('validate')->willReturn(false);
        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')->willReturn('secret-key');
        $request = $this->createMock(Http::class);
        $request->method('getHeader')->with('X-Form-Key')->willReturn('secret-key');

        $guard = new FormKeyGuard($validator, $formKey);
        $this->assertTrue($guard->isValid($request));
    }

    public function testInvalidWhenHeaderDoesNotMatch(): void
    {
        $validator = $this->createMock(Validator::class);
        $validator->method('validate')->willReturn(false);
        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')->willReturn('secret-key');
        $request = $this->createMock(Http::class);
        $request->method('getHeader')->with('X-Form-Key')->willReturn('wrong-key');

        $guard = new FormKeyGuard($validator, $formKey);
        $this->assertFalse($guard->isValid($request));
    }

    public function testInvalidWhenHeaderIsMissing(): void
    {
        $validator = $this->createMock(Validator::class);
        $validator->method('validate')->willReturn(false);
        $formKey = $this->createMock(FormKey::class);
        $request = $this->createMock(Http::class);
        $request->method('getHeader')->with('X-Form-Key')->willReturn(false);

        $guard = new FormKeyGuard($validator, $formKey);
        $this->assertFalse($guard->isValid($request));
    }
}
