<?php
use App\Setup\EnvironmentSchema;

$inputTypes = [
    EnvironmentSchema::TEXT => 'text',
    EnvironmentSchema::URL => 'url',
    EnvironmentSchema::EMAIL => 'email',
    EnvironmentSchema::NUMBER => 'number',
    EnvironmentSchema::PASSWORD => 'password',
];
?>
<div class="card">
    <h2><?= $e($t('Configuration')) ?></h2>
    <p class="muted"><?= $e($t('These options are written to the .env file at the root of Agorakit. The database is checked before anything is written.')) ?></p>

    <?php if ($error): ?>
        <div class="flash error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $e($self) ?>">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <input type="hidden" name="action" value="environment">

        <?php foreach ($sections as $section): ?>
            <fieldset>
                <legend><?= $e($t($section['title'])) ?></legend>
                <?php if (!empty($section['help'])): ?>
                    <div class="help"><?= $e($t($section['help'])) ?></div>
                <?php endif; ?>

                <?php foreach ($section['fields'] as $field): ?>
                    <?php
                    $key = $field['key'];
                    $id = 'env-' . strtolower($key);
                    $name = 'env[' . $key . ']';
                    ?>
                    <?php if ($field['type'] === EnvironmentSchema::BOOLEAN): ?>
                        <div class="check">
                            <input type="checkbox" id="<?= $e($id) ?>" name="<?= $e($name) ?>" value="1" <?= $values[$key] === 'true' ? 'checked' : '' ?>>
                            <label for="<?= $e($id) ?>" style="margin: 0"><?= $e($t($field['label'])) ?></label>
                        </div>
                    <?php else: ?>
                        <label for="<?= $e($id) ?>">
                            <?= $e($t($field['label'])) ?><?= !empty($field['required']) ? ' *' : '' ?>
                        </label>
                        <?php if ($field['type'] === EnvironmentSchema::SELECT): ?>
                            <select id="<?= $e($id) ?>" name="<?= $e($name) ?>">
                                <?php foreach ($field['options'] as $option => $optionLabel): ?>
                                    <option value="<?= $e($option) ?>" <?= (string) $option === $values[$key] ? 'selected' : '' ?>>
                                        <?= $e($t($optionLabel)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="<?= $e($inputTypes[$field['type']]) ?>" id="<?= $e($id) ?>" name="<?= $e($name) ?>"
                                value="<?= $e($values[$key]) ?>"
                                <?= !empty($field['required']) && !$kept[$key] ? 'required' : '' ?>
                                <?= $field['type'] === EnvironmentSchema::PASSWORD ? 'autocomplete="new-password"' : '' ?>>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($kept[$key]): ?>
                        <div class="help"><?= $e($t('Leave empty to keep the current password.')) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($field['help'])): ?>
                        <div class="help"><?= $e($t($field['help'])) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($errors[$key])): ?>
                        <div class="help" style="color: var(--bad)" role="alert"><?= $e($t($errors[$key])) ?></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>

        <button type="submit"><?= $e($t('Check and save')) ?></button>
        <a href="<?= $e($self) ?>" style="margin-left: 1rem"><?= $e($t('Back to the setup page')) ?></a>
    </form>
</div>
