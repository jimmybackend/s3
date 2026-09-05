from pathlib import Path

path = Path('drive/ec2.php')
text = path.read_text(encoding='utf-8')

# Imports: helpers replace procedural functions. Remove no-longer-used SDK/gateway imports.
text = text.replace('use ArcadeCloud\\Drive\\View\\PersonalAwsPageRenderer;\n', 'use ArcadeCloud\\Drive\\View\\PersonalAwsPageRenderer;\nuse ArcadeCloud\\Drive\\View\\Ec2PanelHelper as H;\n')
for line in [
    'use Aws\\Ec2\\Ec2Client;\n',
    'use Aws\\Rds\\RdsClient;\n',
    'use ArcadeCloud\\Drive\\Aws\\Ec2Gateway;\n',
    'use ArcadeCloud\\Drive\\Aws\\RdsGateway;\n',
]:
    text = text.replace(line, '')

start = '// ===================== Clientes AWS =====================\n// ===================== Funciones auxiliares =====================\n'
end = '// ===================== DOWNLOAD RDP =====================\n'
if start not in text or end not in text:
    raise SystemExit('EC2_HELPER_MARKERS_NOT_FOUND')
before, rest = text.split(start, 1)
_old_helpers, after = rest.split(end, 1)
text = before + end + after

# RDP helpers.
text = text.replace('ip_to_ec2_dns_compute1($pip)', 'H::ipv4ToEc2Dns($pip)')
text = text.replace('default_rdp_content()', 'H::defaultRdpContent()')
text = text.replace('set_rdp_full_address($rdp, $host)', 'H::setRdpFullAddress($rdp, $host)')

# Policy helpers.
text = text.replace('is_manual_database_id($id)', 'H::isManualDatabase($id, MANUAL_DATABASE_IDS)')
text = text.replace('database_state_class($dbStatus)', 'H::databaseStateClass($dbStatus)')
text = text.replace('is_protected_id($id)', 'H::isProtected($id)')
text = text.replace("getTag($i, 'Name')", "H::tag($i, 'Name')")

# Escape helper is used only from PHP templates/embedded PHP expressions.
text = text.replace('<?= e(', '<?= H::e(')
text = text.replace("'.e($multiAz).'", "'.H::e($multiAz).'")

if '\nfunction ' in text:
    raise SystemExit('GLOBAL_PHP_FUNCTION_REMAINS')

path.write_text(text, encoding='utf-8')
print('EC2_VIEW_OOP_OK')
