<?php
declare(strict_types=1);

const DAVESTUNES_RELEASE_VERSION='1.0.0-rc1';
const DAVESTUNES_RELEASE_CHANNEL='soft-launch';
const DAVESTUNES_RELEASE_LABEL='Soft Launch RC1';

function dt_release_version(): string { return DAVESTUNES_RELEASE_VERSION; }
function dt_release_channel(): string { return DAVESTUNES_RELEASE_CHANNEL; }
function dt_release_label(): string { return DAVESTUNES_RELEASE_LABEL; }
