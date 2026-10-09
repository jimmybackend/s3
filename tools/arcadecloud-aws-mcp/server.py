"""Private MCP endpoint: bind to loopback and connect only via authenticated tunnel.

Do not expose port 8765 directly to the Internet.
"""
from __future__ import annotations

from mcp.server.mcpserver import MCPServer
from bridge import AwsBridge

bridge = AwsBridge()
mcp = MCPServer(
    "ArcadeCloud AWS",
    instructions=(
        "Administra exclusivamente las instancias small y large configuradas. "
        "Consulta antes de actuar; exige autorización del usuario antes de "
        "apagar, encender o ejecutar comandos que cambien el sistema. "
        "Nunca reveles credenciales o valores de secretos."
    ),
)


@mcp.tool()
def list_ec2() -> list[dict]:
    """Lista el estado real de las EC2 permitidas, nunca toda la cuenta AWS."""
    return bridge.inventory()


@mcp.tool()
def describe_ec2(alias: str) -> dict:
    """Consulta small o large: tipo, estado, región e IP."""
    return bridge.describe(alias)


@mcp.tool()
def aws_identity(alias: str = "small") -> dict:
    """Identidad IAM de control, sin devolver ningún secreto."""
    return bridge.identity(alias)


@mcp.tool()
def diagnose_ec2(alias: str, report: str) -> dict:
    """Solicita diagnóstico SSM: report=host, docker o services; devuelve command_id."""
    return bridge.diagnostics(alias, report)


@mcp.tool()
def command_status(alias: str, command_id: str) -> dict:
    """Recupera estado y salida de un comando SSM previo de la instancia permitida."""
    return bridge.command_result(alias, command_id)


@mcp.tool()
def power_ec2(alias: str, action: str) -> dict:
    """MODIFICA AWS: start o stop; protegido por ARCADECLOUD_MCP_ENABLE_POWER."""
    return bridge.power(alias, action)


@mcp.tool()
def execute_shell(alias: str, command: str, reason: str) -> dict:
    """PRIVILEGIADO: comando Linux mediante SSM; deshabilitado sin ENABLE_SHELL=1."""
    return bridge.shell(alias, command, reason)


if __name__ == "__main__":
    # Only localhost! Publish to ChatGPT through Secure MCP Tunnel.
    mcp.run(
        transport="streamable-http",
        host="127.0.0.1", port=8765,
        json_response=True, stateless_http=True,
    )
