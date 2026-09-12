/**
 * The icon set.
 *
 * Line icons on a 24px grid at a uniform 1.75 stroke, currentColor throughout,
 * so an icon inherits the colour of whatever it sits in and there is no second
 * palette to keep in step. Emoji are not used as product icons — they render
 * differently on every platform and cannot take a colour.
 */

import type { SVGProps } from 'react'

export type IconName =
  | 'home' | 'note' | 'notebook' | 'sparkle-folder' | 'shared' | 'bell' | 'template'
  | 'archive' | 'trash' | 'settings' | 'help' | 'plus' | 'search' | 'pin' | 'pin-filled'
  | 'star' | 'star-filled' | 'checklist' | 'mic' | 'image' | 'scan' | 'draw' | 'meeting'
  | 'attach' | 'upload' | 'clipboard' | 'more' | 'chevron-right' | 'chevron-down'
  | 'chevron-left' | 'close' | 'check' | 'bold' | 'italic' | 'underline' | 'strike'
  | 'highlight' | 'link' | 'heading' | 'list' | 'ordered-list' | 'quote' | 'code'
  | 'divider' | 'table' | 'callout' | 'pulse' | 'history' | 'info' | 'comment'
  | 'share' | 'copy' | 'download' | 'print' | 'sun' | 'moon' | 'monitor' | 'cloud-off'
  | 'cloud-check' | 'refresh' | 'lock' | 'palette' | 'tag' | 'calendar' | 'user'
  | 'alert' | 'undo' | 'grid' | 'rows' | 'drag' | 'file' | 'play' | 'pause' | 'stop'

/** Path data only — every icon shares the wrapper's stroke and sizing. */
const PATHS: Record<IconName, string> = {
  home: 'M3 10.2 12 3l9 7.2M5.5 8.8V20h13V8.8M9.5 20v-6h5v6',
  note: 'M5 3.5h9.5L19 8v12.5H5zM14 3.5V8h5',
  notebook: 'M6 3.5h11a1.5 1.5 0 0 1 1.5 1.5v14a1.5 1.5 0 0 1-1.5 1.5H6zM6 3.5v17M9.5 3.5v17',
  'sparkle-folder': 'M3 6.5A1.5 1.5 0 0 1 4.5 5h4l2 2.5h7A1.5 1.5 0 0 1 19 9v8.5a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 3 17.5zM12.5 11l.9 2.1 2.1.9-2.1.9-.9 2.1-.9-2.1-2.1-.9 2.1-.9z',
  shared: 'M16 6.5a2.5 2.5 0 1 0 0-.01M7 12.5a2.5 2.5 0 1 0 0-.01M16 18.5a2.5 2.5 0 1 0 0-.01M9.2 11.3l4.6-3.1M9.2 13.7l4.6 3.1',
  bell: 'M12 3.5a5 5 0 0 0-5 5v3.2L5.5 15h13L17 11.7V8.5a5 5 0 0 0-5-5zM10 18a2 2 0 0 0 4 0',
  template: 'M4 5.5h16v13H4zM4 10h16M9.5 10v8.5',
  archive: 'M3.5 5.5h17V9h-17zM5 9v10h14V9M10 13h4',
  trash: 'M4.5 6.5h15M9.5 6.5V4h5v2.5M6.5 6.5 7.5 20h9l1-13.5M10.5 10v6M13.5 10v6',
  settings: 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6M19.4 14.5a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-2.7-1.1l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0-1.1-2.7H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.1-2.7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 2.7-1.1V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1',
  help: 'M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17M9.6 9.4a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .9-1 1.6v.4M12 17h.01',
  plus: 'M12 5v14M5 12h14',
  search: 'M11 4.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13M20 20l-4.4-4.4',
  pin: 'M9.5 3.5h5l-.7 5.2 3.2 3v1.8h-5.5m-2.5 0H3.5v-1.8l3.2-3-.7-5.2M12 13.5V21',
  'pin-filled': 'M9.5 3.5h5l-.7 5.2 3.2 3v1.8H6.5v-1.8l3.2-3zM12 13.5V21',
  star: 'M12 3.8l2.6 5.3 5.9.9-4.2 4.1 1 5.8-5.3-2.8-5.3 2.8 1-5.8-4.2-4.1 5.9-.9z',
  'star-filled': 'M12 3.8l2.6 5.3 5.9.9-4.2 4.1 1 5.8-5.3-2.8-5.3 2.8 1-5.8-4.2-4.1 5.9-.9z',
  checklist: 'M4 6.5l1.8 1.8L9 5M4 15.5l1.8 1.8L9 14M12 7h8M12 16h8',
  mic: 'M12 3.5a2.5 2.5 0 0 0-2.5 2.5v6a2.5 2.5 0 0 0 5 0V6A2.5 2.5 0 0 0 12 3.5M6 11v1a6 6 0 0 0 12 0v-1M12 18v3M9 21h6',
  image: 'M4 5.5h16v13H4zM4 15l4.5-4.5 4 4 3-3L20 15M9 10a1.2 1.2 0 1 0 0-.01',
  scan: 'M4 8.5V5.5h3M20 8.5V5.5h-3M4 15.5v3h3M20 15.5v3h-3M7 12h10',
  draw: 'M4 20l1-4 10.5-10.5a2.1 2.1 0 0 1 3 3L8 19zM14 7l3 3',
  meeting: 'M4 6.5h16v11H4zM8 6.5V4M16 6.5V4M4 10h16M8.5 13.5h3M14 13.5h1.5',
  attach: 'M17.5 9.5 10 17a3.5 3.5 0 0 1-5-5l8-8a2.5 2.5 0 0 1 3.5 3.5l-7.5 7.5a1.2 1.2 0 0 1-1.7-1.7l7-7',
  upload: 'M12 16V4M8 7.5 12 3.5l4 4M4.5 15v4.5h15V15',
  clipboard: 'M9 4.5h6v2.5H9zM7 6h-.5A1.5 1.5 0 0 0 5 7.5v11A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5v-11A1.5 1.5 0 0 0 17.5 6H17',
  more: 'M12 6.5h.01M12 12h.01M12 17.5h.01',
  'chevron-right': 'M9.5 5.5 16 12l-6.5 6.5',
  'chevron-down': 'M5.5 9.5 12 16l6.5-6.5',
  'chevron-left': 'M14.5 5.5 8 12l6.5 6.5',
  close: 'M6 6l12 12M18 6 6 18',
  check: 'M5 12.5 9.5 17 19 7.5',
  bold: 'M7 4.5h6a3.75 3.75 0 0 1 0 7.5H7zM7 12h6.8a3.75 3.75 0 0 1 0 7.5H7z',
  italic: 'M10 4.5h7M7 19.5h7M14.5 4.5 9.5 19.5',
  underline: 'M7 4v7a5 5 0 0 0 10 0V4M5.5 20h13',
  strike: 'M4.5 12h15M7.5 8a3.5 3.5 0 0 1 3.5-3.5h2A3.5 3.5 0 0 1 16.5 8M16 16a3.5 3.5 0 0 1-3.5 3.5h-2A3.5 3.5 0 0 1 7 16',
  highlight: 'M8 15.5 5 18.5v2h4l2-2M8 15.5 15.5 8a2 2 0 0 1 3 0v0a2 2 0 0 1 0 3L11 18.5M8 15.5 11 18.5',
  link: 'M10 13.5a3.5 3.5 0 0 0 5 0l2.5-2.5a3.5 3.5 0 0 0-5-5L11 7.5M14 10.5a3.5 3.5 0 0 0-5 0L6.5 13a3.5 3.5 0 0 0 5 5l1.5-1.5',
  heading: 'M6 4.5v15M18 4.5v15M6 12h12',
  list: 'M9 6.5h11M9 12h11M9 17.5h11M4.5 6.5h.01M4.5 12h.01M4.5 17.5h.01',
  'ordered-list': 'M10 6.5h10M10 12h10M10 17.5h10M4 4.5h1.2v4M3.6 15.5a1.2 1.2 0 1 1 2 .9L3.6 19h2.4',
  quote: 'M8.5 6.5C6 7.5 5 9.5 5 12v5.5h5V12H7.8c0-1.6.5-2.7 2-3.4zM18 6.5c-2.5 1-3.5 3-3.5 5.5v5.5h5V12h-2.2c0-1.6.5-2.7 2-3.4z',
  code: 'M8.5 8 4.5 12l4 4M15.5 8l4 4-4 4M13.5 5l-3 14',
  divider: 'M4 12h16',
  table: 'M4 5.5h16v13H4zM4 10h16M4 14.5h16M10 5.5v13M15 5.5v13',
  callout: 'M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17M12 8v5M12 16h.01',
  pulse: 'M3.5 12h3.5l2-5 3 10 2.5-6 1.5 3h4.5',
  history: 'M3.5 12a8.5 8.5 0 1 0 2.6-6.1M3.5 5v4h4M12 7.5V12l3 2',
  info: 'M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17M12 11v5.5M12 8h.01',
  comment: 'M20 12.5c0 3.9-3.6 7-8 7a9.4 9.4 0 0 1-2.6-.4L4.5 20.5l1.2-3.4A6.7 6.7 0 0 1 4 12.5c0-3.9 3.6-7 8-7s8 3.1 8 7',
  share: 'M8.5 13.5a2.5 2.5 0 1 0 0-3 2.5 2.5 0 0 0 0 3M16.5 8.5a2.5 2.5 0 1 0 0-.01M16.5 18.5a2.5 2.5 0 1 0 0-.01M10.8 10.8l3.4-1.8M10.8 13.2l3.4 1.8',
  copy: 'M8.5 8.5h10v11h-10zM5.5 15.5h-1V4.5h10v1',
  download: 'M12 4v11M8 11.5l4 4 4-4M4.5 19.5h15',
  print: 'M7 9V4.5h10V9M5 9h14a1 1 0 0 1 1 1v6h-4M8 16H4v-6a1 1 0 0 1 1-1M8 13.5h8v6H8z',
  sun: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8M12 2.5v2M12 19.5v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M2.5 12h2M19.5 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
  moon: 'M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5',
  monitor: 'M3.5 5.5h17v10h-17zM8.5 20h7M12 15.5V20',
  'cloud-off': 'M3 3l18 18M7.5 17.5A4 4 0 0 1 7 9.6M9.7 6.6A5.5 5.5 0 0 1 18 10.5h.2a3.5 3.5 0 0 1 2.4 6M17 17.5H11',
  'cloud-check': 'M7.5 17.5a4 4 0 0 1-.5-8 5.5 5.5 0 0 1 11 1h.2a3.5 3.5 0 0 1 0 7zM9.5 13.5l2 2 3.5-3.5',
  refresh: 'M20 12a8 8 0 1 1-2.5-5.8M20 3.5v4.5h-4.5',
  lock: 'M7 10.5V8a5 5 0 0 1 10 0v2.5M5.5 10.5h13v9h-13zM12 14v2.5',
  palette: 'M12 3.5c-4.7 0-8.5 3.6-8.5 8s3.8 8 8.5 8c1 0 1.5-.7 1.5-1.5 0-.4-.2-.8-.4-1-.3-.3-.4-.6-.4-1 0-.8.6-1.5 1.5-1.5h1.3c2.5 0 4.5-2 4.5-4.5 0-3.6-3.4-6.5-8-6.5M7.5 12a1 1 0 1 0 0-.01M9.5 8.5a1 1 0 1 0 0-.01M14 8a1 1 0 1 0 0-.01M17 11a1 1 0 1 0 0-.01',
  tag: 'M3.5 11V4.5H10L20 14.5 13.5 21zM7 8a1 1 0 1 0 0-.01',
  calendar: 'M4 6.5h16v13H4zM8 6.5V4M16 6.5V4M4 10.5h16M8 14h2M13 14h3M8 17h2',
  user: 'M12 4a3.75 3.75 0 1 0 0 7.5A3.75 3.75 0 0 0 12 4M5 20.5a7 7 0 0 1 14 0',
  alert: 'M12 4 2.5 20h19zM12 10v4.5M12 17.5h.01',
  undo: 'M4 9.5h9a5.5 5.5 0 0 1 0 11H8M4 9.5 8 5.5M4 9.5l4 4',
  grid: 'M4 4.5h6.5V11H4zM13.5 4.5H20V11h-6.5zM4 13.5h6.5V20H4zM13.5 13.5H20V20h-6.5z',
  rows: 'M4 5.5h16v4H4zM4 14.5h16v4H4z',
  drag: 'M9.5 6.5h.01M14.5 6.5h.01M9.5 12h.01M14.5 12h.01M9.5 17.5h.01M14.5 17.5h.01',
  file: 'M6 3.5h8L18.5 8v12.5h-13zM14 3.5V8h4.5',
  play: 'M7.5 4.5 19 12 7.5 19.5z',
  pause: 'M8 5v14M16 5v14',
  stop: 'M6 6h12v12H6z',
}

/** Icons drawn as solid shapes rather than outlines. */
const FILLED = new Set<IconName>(['pin-filled', 'star-filled', 'play', 'stop'])

export interface IconProps extends Omit<SVGProps<SVGSVGElement>, 'name'> {
  name: IconName
  size?: number
  /**
   * Screen-reader label. Omit for an icon that only decorates a labelled
   * control — announcing "pin icon" next to a button already called "Pin" is
   * noise, so the default is aria-hidden.
   */
  label?: string
}

export function Icon({ name, size = 18, label, ...rest }: IconProps) {
  const filled = FILLED.has(name)

  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill={filled ? 'currentColor' : 'none'}
      stroke="currentColor"
      strokeWidth={1.75}
      strokeLinecap="round"
      strokeLinejoin="round"
      role={label ? 'img' : undefined}
      aria-label={label}
      aria-hidden={label ? undefined : true}
      focusable="false"
      {...rest}
    >
      <path d={PATHS[name]} />
    </svg>
  )
}
