<?php

namespace Tests\MDC;

use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationFileConfiguration;
use MestresDaCultura\RegistrationPdf;
use Tests\Abstract\TestCase;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\RegistrationDirector;
use Tests\Traits\UserDirector;

// O PDF da ficha do tema MestresDaCultura: etapas, seções, respostas e anexos.
class RegistrationPdfTest extends TestCase
{
    use GalleryPhotos,
        OpportunityBuilder,
        RegistrationDirector,
        UserDirector;

    private Opportunity $opportunity;

    private function registration(): Registration
    {
        // Só o super administrador do SaaS cria oportunidade no tema.
        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($admin);

        $this->opportunity = $this->opportunityBuilder
            ->reset(owner: $admin->profile, owner_entity: $admin->profile)
            ->fillRequiredProperties()
            ->save()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->createStep('Dados Pessoais', 1)
                    ->createField('contato', 'section', title: 'Contato')
                    ->createField('aceite', 'checkbox', title: 'Aceito que meu telefone fique público')
                    ->createField('publico', 'text', title: 'Telefone (público)', field_condition: 'aceite:1')
                    ->createField('privado', 'text', title: 'Telefone (privado)', field_condition: 'aceite:0')
                    ->createField('vazia', 'section', title: 'Seção sem resposta')
                    ->createField('nada', 'text', title: 'Sem resposta')
                    ->createField('outros', 'section', title: 'Outros')
                    ->createField('nascimento', 'date', title: 'Data de nascimento:')
                    ->createField('comunidade', 'text', title: 'Pertence a uma comunidade?')
                    ->createField('lgpd', 'checkbox', title: 'Declaro que as informações são verdadeiras.', required: true)
                ->createStep('Etapa sem resposta', 2)
                    ->createField('ignorado', 'text', title: 'Ignorado')
                    ->createField('fotos', 'agent-owner-field', title: 'Fotos', config: ['entityField' => '@gallery'])
                ->done()
            ->save()
            ->refresh()
            ->getInstance();

        $registration = $this->registrationDirector->createDraftRegistrations($this->opportunity, 1)[0];
        $registration->opportunity->registerRegistrationMetadata();
        $registration->registerFieldsMetadata();

        return $registration;
    }

    private function answer(Registration $registration, array $answers): Registration
    {
        // Grava pelo mesmo caminho do formulário.
        $values = [];

        foreach ($answers as $identifier => $value) {
            $values[$this->opportunityBuilder->getFieldName($identifier)] = $value;
        }

        $this->app->controller('registration')->setEntityProperties($registration, $values);
        $registration->save(true);

        // Relê do banco, como numa requisição nova.
        $this->app->em->clear();
        $registration = $this->app->repo('Registration')->find($registration->id);
        $registration->opportunity->registerRegistrationMetadata();
        $registration->registerFieldsMetadata();

        return $registration;
    }

    /** @return array<string,string> rótulo => valor, de todas as etapas */
    private function items(RegistrationPdf $pdf): array
    {
        $items = [];

        foreach ($pdf->steps() as $step) {
            foreach ($step['sections'] as $section) {
                foreach ($section['items'] as $item) {
                    $items[$item['label']] = $item['html'];
                }
            }
        }

        return $items;
    }

    function testGroupsTheAnswersByStepAndSectionAndLeavesOutWhatWasNotAnswered()
    {
        $registration = $this->answer($this->registration(), ['aceite' => true, 'publico' => '(88) 1111-1111', 'comunidade' => 'Sim']);

        $steps = (new RegistrationPdf($registration))->steps();

        $this->assertSame(['Dados Pessoais'], array_column($steps, 'name'), 'Etapa sem nenhuma resposta não aparece');
        $this->assertSame(['Contato', 'Outros'], array_column($steps[0]['sections'], 'title'), 'Seção sem nenhuma resposta não aparece');
    }

    function testWritesTheCheckboxAsYesOrNoAndShowsWhatDependsOnItBeingUnchecked()
    {
        $registration = $this->answer($this->registration(), ['aceite' => false, 'publico' => 'resposta antiga', 'privado' => '(88) 2222-2222']);

        $items = $this->items(new RegistrationPdf($registration));

        $this->assertSame('Não', $items['Aceito que meu telefone fique público:'] ?? null, 'Caixa desmarcada é a resposta "Não"');
        $this->assertSame('(88) 2222-2222', $items['Telefone (privado):'] ?? null, 'O formulário mostra o campo que depende da caixa desmarcada');
        $this->assertArrayNotHasKey('Telefone (público):', $items, 'O ramo que o formulário escondeu fica de fora, mesmo com resposta gravada');
    }

    function testWritesDatesAsDayMonthYearAndDoesNotRepeatTheLabelPunctuation()
    {
        $registration = $this->answer($this->registration(), ['nascimento' => '1952-03-19', 'comunidade' => 'Sim', 'lgpd' => true]);

        $items = $this->items(new RegistrationPdf($registration));

        $this->assertSame('19/03/1952', $items['Data de nascimento:'] ?? null, 'Título terminado em ":" não ganha outro');
        $this->assertSame('Sim', $items['Pertence a uma comunidade?'] ?? null, 'Título terminado em "?" não ganha ":"');
        $this->assertSame('Sim', $items['* Declaro que as informações são verdadeiras.'] ?? null);
    }

    private function attach(Registration $registration, string $title, string $content, string $type, string $extension): RegistrationFileConfiguration
    {
        $config = new RegistrationFileConfiguration;
        $config->owner = $this->opportunity;
        // O anexo vai na etapa "Dados Pessoais", e não na etapa padrão sem nome.
        $config->step = current(array_filter($this->opportunity->registrationSteps->toArray(), fn ($step) => $step->name === 'Dados Pessoais'));
        $config->title = $title;
        $config->save(true);

        $path = sys_get_temp_dir() . '/mdc-anexo-' . uniqid() . ".{$extension}";
        file_put_contents($path, $content);

        $file = new \MapasCulturais\Entities\RegistrationFile([
            'error' => UPLOAD_ERR_OK,
            'name' => "anexo.{$extension}",
            'type' => $type,
            'tmp_name' => $path,
            'size' => strlen($content),
        ]);
        $file->owner = $registration;
        $file->group = $config->fileGroupName;
        $file->save(true);

        return $config;
    }

    function testNumbersThePdfAttachmentsInTheOrderTheyAreMergedAndTellsWhereEachOneComesFrom()
    {
        $registration = $this->registration();
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

        $this->attach($registration, 'Comprovante', $pdf, 'application/pdf', 'pdf');
        $this->attach($registration, 'Comprovante', $pdf, 'application/pdf', 'pdf');
        $this->attach($registration, 'Foto avulsa', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), 'image/png', 'png');

        $registration = $this->answer($registration, ['comunidade' => 'Sim']);
        $pdf = new RegistrationPdf($registration);

        $this->assertSame([1, 2], array_column($pdf->attachmentsIndex(), 'number'), 'Só os PDFs são juntados e numerados');
        $this->assertSame(['Dados Pessoais › Outros', 'Dados Pessoais › Outros'], array_column($pdf->attachmentsIndex(), 'place'));

        $items = $this->items($pdf);

        $this->assertStringContainsString('Anexo 2, no fim deste documento', $items['Comprovante:'] ?? '', 'Campos de mesmo título: vale o último, o anexo 2');
        $this->assertStringContainsString('não incluído neste PDF', $items['Foto avulsa:'] ?? '', 'O que não é PDF fica de fora do arquivo, e o PDF diz isso');
    }

    // O PDF só embute a foto da galeria de quem se inscreveu; a de outra pessoa fica só com a legenda.
    function testThePhotosAreOnlyTheOwnersGallery()
    {
        $registration = $this->registration();
        $theirs = $this->galleryPhoto($this->userDirector->createUser()->profile);
        $mine = $this->galleryPhoto($registration->owner);

        $this->login($registration->owner->user);
        $registration = $this->answer($registration, ['lgpd' => true, 'fotos' => [
            ['id' => $theirs->id, 'description' => 'De outra pessoa'],
            ['id' => $mine->id, 'description' => 'Roda de samba'],
        ]]);
        $stored = json_decode((string) $this->app->conn->fetchOne('SELECT value FROM registration_meta WHERE object_id = ? AND key = ?', [$registration->id, $this->opportunityBuilder->getFieldName('fotos')]), true);
        $this->assertContains($theirs->id, array_column((array) $stored, 'id'), 'O formulário grava o id que vier');

        $photos = fn (Registration $registration): array => array_column((array) $registration->{$this->opportunityBuilder->getFieldName('fotos')}, 'id');
        $this->assertSame([$mine->id], $photos($registration), 'No rascunho, só a galeria de quem se inscreveu');
        $this->assertSame(1, substr_count($this->items(new RegistrationPdf($registration))['Fotos:'], 'data:image'));

        $registration->send();
        $this->app->em->clear();
        $sent = $this->app->repo('Registration')->find($registration->id);
        $sent->opportunity->registerRegistrationMetadata();
        $sent->registerFieldsMetadata();
        $this->assertContains($theirs->id, $photos($sent), 'Enviada, a resposta traz o id alheio');

        $html = $this->items(new RegistrationPdf($sent))['Fotos:'];
        $this->assertSame(1, substr_count($html, 'data:image'), 'Só a foto da galeria da inscrita entra no PDF');
        $this->assertStringContainsString('<div class="photo">De outra pessoa</div>', $html, 'A de outra pessoa fica só com a legenda');
    }
}
