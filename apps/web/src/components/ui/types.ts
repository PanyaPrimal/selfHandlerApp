export interface UiOption<V = string> {
  value: V
  label: string
  description?: string
  disabled?: boolean
}

export interface UiSwatchOption<V = string> extends UiOption<V> {
  color: string
  hex: string
}

export type UiTextInputType = 'text' | 'email' | 'password' | 'search'
