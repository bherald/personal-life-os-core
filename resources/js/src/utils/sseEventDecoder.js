export class SseEventDecoder {
  constructor() {
    this.decoder = new TextDecoder();
    this.lineBuffer = '';
    this.dataLines = [];
    this.eventType = '';
    this.lastEventId = '';
  }

  feed(chunk) {
    return this.consumeText(this.decoder.decode(chunk, { stream: true }));
  }

  finish() {
    const events = this.consumeText(this.decoder.decode());

    if (this.lineBuffer !== '') {
      const finalLine = this.lineBuffer.endsWith('\r')
        ? this.lineBuffer.slice(0, -1)
        : this.lineBuffer;
      this.processLine(finalLine, events);
      this.lineBuffer = '';
    }

    this.dispatch(events);

    return events;
  }

  consumeText(text) {
    this.lineBuffer += text;
    const events = [];
    let start = 0;
    let index = 0;

    while (index < this.lineBuffer.length) {
      const character = this.lineBuffer[index];

      if (character !== '\r' && character !== '\n') {
        index += 1;
        continue;
      }

      if (character === '\r' && index + 1 === this.lineBuffer.length) {
        break;
      }

      this.processLine(this.lineBuffer.slice(start, index), events);
      index += character === '\r' && this.lineBuffer[index + 1] === '\n' ? 2 : 1;
      start = index;
    }

    this.lineBuffer = this.lineBuffer.slice(start);

    return events;
  }

  processLine(line, events) {
    if (line === '') {
      this.dispatch(events);
      return;
    }

    if (line.startsWith(':')) return;

    const separator = line.indexOf(':');
    const field = separator === -1 ? line : line.slice(0, separator);
    let value = separator === -1 ? '' : line.slice(separator + 1);
    if (value.startsWith(' ')) value = value.slice(1);

    switch (field) {
      case 'data':
        this.dataLines.push(value);
        break;
      case 'event':
        this.eventType = value;
        break;
      case 'id':
        if (!value.includes('\0')) this.lastEventId = value;
        break;
    }
  }

  dispatch(events) {
    if (this.dataLines.length === 0) {
      this.eventType = '';
      return;
    }

    events.push({
      data: this.dataLines.join('\n'),
      event: this.eventType || 'message',
      id: this.lastEventId,
    });
    this.dataLines = [];
    this.eventType = '';
  }
}
