<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Agent;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

// No subsite dos Mestres, faltar a descrição curta e a área de atuação não conta como erro de validação do perfil; o
// resto da regra segue.
class ProfileValidationTest extends TestCase
{
    use UserDirector;

    private function profileWithout(array $properties): Agent
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $profile = $this->app->repo('Agent')->find($user->profile->id);

        foreach ($properties as $property => $value) {
            $profile->$property = $value;
        }
        $profile->terms['area'] = [];

        return $profile;
    }

    function testWhatTheProgramDoesNotAskIsNotAnError()
    {
        $errors = $this->profileWithout(['shortDescription' => ''])->validationErrors;

        $this->assertArrayNotHasKey('shortDescription', $errors, 'Sem descrição curta');
        $this->assertArrayNotHasKey('term-area', $errors, 'Sem área de atuação');
    }

    function testWhatTheProgramUsesStillCounts()
    {
        $this->assertArrayHasKey('name', $this->profileWithout(['shortDescription' => '', 'name' => ''])->validationErrors, 'Sem nome, segue o erro');
        $this->assertArrayHasKey('shortDescription', $this->profileWithout(['shortDescription' => str_repeat('a', 401)])->validationErrors, 'A descrição curta segue com o limite de tamanho');
    }
}
