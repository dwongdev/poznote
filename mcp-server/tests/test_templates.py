"""Templates reachable from MCP (issue #1364).

Before this, GET /api/v1/notes/templates existed but no tool reached it, so
an agent could only use a template whose note id it already knew.
"""

from unittest.mock import MagicMock, patch

import json

from poznote_mcp.client import PoznoteClient
from poznote_mcp import server


def _mock_response(payload):
    response = MagicMock()
    response.raise_for_status.return_value = None
    response.json.return_value = payload
    response.status_code = 200
    return response


@patch("poznote_mcp.client.httpx.Client")
def test_list_templates_calls_the_templates_endpoint(mock_client_cls):
    http_client = MagicMock()
    http_client.get.return_value = _mock_response(
        {"success": True, "notes": [{"id": 7, "heading": "Meeting", "type": "markdown"}]}
    )
    mock_client_cls.return_value = http_client

    client = PoznoteClient(base_url="http://example.test/api/v1", service_token="secret-token")
    notes = client.list_templates(workspace="Demo")

    args, kwargs = http_client.get.call_args
    assert args[0] == "/notes/templates"
    assert kwargs["params"] == {"workspace": "Demo"}
    assert notes[0]["id"] == 7


def test_list_templates_tool_formats_the_result():
    client = MagicMock()
    client.list_templates.return_value = [
        {"id": 7, "heading": "Meeting", "type": "markdown", "workspace": "Demo", "folder_id": 3}
    ]

    with patch.object(server, "_get_client_or_error", return_value=(client, None)):
        payload = json.loads(server.list_templates(workspace="Demo"))

    assert payload["count"] == 1
    assert payload["workspace"] == "Demo"
    assert payload["templates"][0] == {
        "id": 7,
        "title": "Meeting",
        "note_type": "markdown",
        "workspace": "Demo",
        "folder_id": 3,
    }


def test_create_note_from_template_copies_its_content_and_format():
    client = MagicMock()
    client.get_note.return_value = {"id": 7, "heading": "Meeting", "type": "markdown", "content": "# Agenda\n"}
    client.create_note.return_value = {"id": 42}

    with patch.object(server, "_get_client_or_error", return_value=(client, None)):
        payload = json.loads(server.create_note(title="Monday", from_template_id=7, workspace="Demo"))

    assert payload["success"] is True
    _, kwargs = client.create_note.call_args
    assert kwargs["content"] == "# Agenda\n"
    assert kwargs["note_type"] == "markdown"


def test_create_note_appends_its_own_content_after_the_template():
    client = MagicMock()
    client.get_note.return_value = {"id": 7, "heading": "Meeting", "type": "note", "content": "<p>Agenda</p>"}
    client.create_note.return_value = {"id": 42}

    with patch.object(server, "_get_client_or_error", return_value=(client, None)):
        server.create_note(title="Monday", content="<p>Notes</p>", from_template_id=7)

    _, kwargs = client.create_note.call_args
    assert kwargs["content"] == "<p>Agenda</p><p>Notes</p>"


def test_create_note_reports_a_missing_template():
    client = MagicMock()
    client.get_note.return_value = None

    with patch.object(server, "_get_client_or_error", return_value=(client, None)):
        payload = json.loads(server.create_note(title="Monday", from_template_id=999))

    assert "not found" in payload["error"]
    client.create_note.assert_not_called()


def test_create_note_still_requires_content_without_a_template():
    client = MagicMock()

    with patch.object(server, "_get_client_or_error", return_value=(client, None)):
        payload = json.loads(server.create_note(title="Monday"))

    assert "content is required" in payload["error"]
    client.create_note.assert_not_called()


def test_a_template_cannot_start_a_tasklist():
    """A template is text; pasted into a tasklist it would be invalid JSON."""
    client = MagicMock()
    client.get_note.return_value = {"id": 7, "heading": "T", "type": "markdown", "content": "# Agenda"}

    with patch.object(server, "_get_client_or_error", return_value=(client, None)):
        payload = json.loads(server.create_note(title="Monday", from_template_id=7, note_type="tasklist"))

    assert "cannot start a tasklist" in payload["error"]
    client.create_note.assert_not_called()


def test_asking_markdown_from_an_html_template_converts_it():
    client = MagicMock()
    client.list_workspaces.return_value = [{"name": "Poznote"}]
    client.get_note.return_value = {"id": 7, "heading": "T", "type": "note", "content": "<h1>Agenda</h1>"}
    client.convert_content.return_value = "# Agenda\n"
    client.create_note.return_value = {"id": 42}

    with patch.object(server, "_get_client_or_error", return_value=(client, None)):
        server.create_note(title="Monday", from_template_id=7, note_type="markdown")

    args, _ = client.convert_content.call_args
    assert args[1:] == ("note", "markdown")
    _, kwargs = client.create_note.call_args
    assert kwargs["content"] == "# Agenda\n"
    assert kwargs["note_type"] == "markdown"


def test_same_format_is_not_converted():
    client = MagicMock()
    client.list_workspaces.return_value = [{"name": "Poznote"}]
    client.get_note.return_value = {"id": 7, "heading": "T", "type": "markdown", "content": "# Agenda\n"}
    client.create_note.return_value = {"id": 42}

    with patch.object(server, "_get_client_or_error", return_value=(client, None)):
        server.create_note(title="Monday", from_template_id=7, note_type="markdown")

    client.convert_content.assert_not_called()


def test_markdown_added_after_a_template_starts_on_its_own_line():
    client = MagicMock()
    client.list_workspaces.return_value = [{"name": "Poznote"}]
    client.get_note.return_value = {"id": 7, "heading": "T", "type": "markdown", "content": "## Notes"}
    client.create_note.return_value = {"id": 42}

    with patch.object(server, "_get_client_or_error", return_value=(client, None)):
        server.create_note(title="Monday", from_template_id=7, content="- first point")

    _, kwargs = client.create_note.call_args
    assert kwargs["content"] == "## Notes\n\n- first point"


@patch("poznote_mcp.client.httpx.Client")
def test_client_converts_through_the_note_converters(mock_client_cls):
    http_client = MagicMock()
    response = MagicMock()
    response.raise_for_status.return_value = None
    response.json.return_value = {"success": True, "markdown": "# Agenda"}
    http_client.post.return_value = response
    mock_client_cls.return_value = http_client

    client = PoznoteClient(base_url="http://example.test/api/v1", service_token="secret-token")
    assert client.convert_content("<h1>Agenda</h1>", "note", "markdown") == "# Agenda"

    args, kwargs = http_client.post.call_args
    assert args[0] == "/convert-html"
    assert kwargs["json"] == {"html": "<h1>Agenda</h1>"}
